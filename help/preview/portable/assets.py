"""Verify and install the checked-in frontend bundle for an isolated preview."""

import hashlib
import io
import json
import re
import runpy
import stat
import tempfile
import zipfile
from contextlib import contextmanager
from pathlib import Path


FRONTENDS = {"admin": "admin", "merchant": "merchant", "h5": "uni-app"}
MAX_ARCHIVE_BYTES = 128 * 1024 * 1024
MAX_OUTPUT_BYTES = 256 * 1024 * 1024
HEX_SHA256 = re.compile(r"[0-9a-f]{64}")
WINDOWS_RESERVED = {"CON", "PRN", "AUX", "NUL", "CONIN$", "CONOUT$"}
WINDOWS_RESERVED.update(f"{prefix}{number}" for prefix in ("COM", "LPT") for number in "123456789¹²³")


def _sha256(data):
    return hashlib.sha256(data).hexdigest()


def _regular(path, directory=False):
    info = path.lstat()
    expected = stat.S_ISDIR if directory else stat.S_ISREG
    if not expected(info.st_mode) or getattr(info, "st_file_attributes", 0) & 0x400:
        raise ValueError(f"Frontend bundle requires a regular {'directory' if directory else 'file'}: {path}")
    return info


def _unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError(f"Duplicate frontend manifest key: {key}")
        result[key] = value
    return result


def _input_signature(repo, template, input_entries):
    origin = repo / "template" / template
    _regular(origin.parent, directory=True)
    _regular(origin, directory=True)
    rows = []
    for relative, directory, files in input_entries(origin):
        _regular(directory, directory=True)
        for leaf in files:
            path = directory / leaf
            _regular(path)
            raw = path.read_bytes()
            rows.append(((relative / leaf).as_posix(), len(raw), _sha256(raw)))
    signature = "".join(f"{path}\0{size}\0{digest}\n" for path, size, digest in sorted(rows))
    return {
        "file_count": len(rows),
        "bytes": sum(size for _, size, _ in rows),
        "copied_input_sha256": _sha256(signature.encode("utf-8")),
    }


def _asset_parts(name):
    parts = name.split("/")
    if len(parts) < 4 or parts[0] != "build" or parts[1] not in FRONTENDS or parts[2] != "dist":
        raise ValueError(f"Unexpected frontend archive path: {name}")
    for part in parts:
        if (not part or part in (".", "..") or part.rstrip(" .") != part
                or any(ord(char) < 32 or char in '\\:<>"|?*' for char in part)
                or part.split(".", 1)[0].upper() in WINDOWS_RESERVED):
            raise ValueError(f"Invalid frontend archive path: {name}")
    return parts


def _validate_manifest(manifest):
    if (not isinstance(manifest, dict) or type(manifest.get("schema")) is not int
            or manifest["schema"] != 1 or not isinstance(manifest.get("source_commit"), str)
            or not re.fullmatch(r"[0-9a-f]{40}", manifest["source_commit"])
            or not isinstance(manifest.get("zip_sha256"), str) or not HEX_SHA256.fullmatch(manifest["zip_sha256"])
            or type(manifest.get("zip_bytes")) is not int or not 0 < manifest["zip_bytes"] <= MAX_ARCHIVE_BYTES
            or not isinstance(manifest.get("frontends"), dict) or set(manifest["frontends"]) != set(FRONTENDS)
            or not isinstance(manifest.get("files"), dict) or not manifest["files"]):
        raise ValueError("Invalid frontend bundle manifest")

    paths = {}
    totals = {frontend: {"files": 0, "bytes": 0} for frontend in FRONTENDS}
    for name, pin in manifest["files"].items():
        parts = _asset_parts(name)
        if (not isinstance(pin, dict) or set(pin) != {"bytes", "sha256"}
                or type(pin["bytes"]) is not int or pin["bytes"] < 0
                or not isinstance(pin["sha256"], str) or not HEX_SHA256.fullmatch(pin["sha256"])):
            raise ValueError(f"Invalid frontend file fingerprint: {name}")
        for length in range(1, len(parts) + 1):
            key = "/".join(parts[:length])
            entry = (key, length == len(parts))
            if paths.setdefault(key.casefold(), entry) != entry:
                raise ValueError(f"Frontend archive path collision: {name}")
        totals[parts[1]]["files"] += 1
        totals[parts[1]]["bytes"] += pin["bytes"]
    if sum(entry["bytes"] for entry in totals.values()) > MAX_OUTPUT_BYTES:
        raise ValueError("Frontend bundle exceeds its maximum unpacked size")
    for frontend, total in totals.items():
        record = manifest["frontends"][frontend]
        entry = f"build/{frontend}/dist/index.html"
        if (not isinstance(record, dict) or record.get("output") != total
                or not isinstance(record.get("source_inputs"), dict)
                or entry not in manifest["files"] or not manifest["files"][entry]["bytes"]):
            raise ValueError(f"Invalid frontend output inventory: {frontend}")


@contextmanager
def _checked_bundle(repo):
    repo = Path(repo).resolve(strict=True)
    package = repo / "help" / "preview" / "portable"
    manifest_path = package / "frontends.json"
    archive_path = package / "frontends.zip"
    _regular(package, directory=True)
    _regular(manifest_path)
    archive_size = _regular(archive_path).st_size
    manifest = json.loads(manifest_path.read_text("utf-8"), object_pairs_hook=_unique_object)
    _validate_manifest(manifest)
    if archive_size != manifest["zip_bytes"]:
        raise ValueError("Frontend ZIP size differs from the manifest")

    input_entries = runpy.run_path(str(package.parent / "build-frontends.py"))["input_entries"]
    for frontend, template in FRONTENDS.items():
        if _input_signature(repo, template, input_entries) != manifest["frontends"][frontend]["source_inputs"]:
            raise ValueError(f"Frontend sources differ from the packaged build: {frontend}")

    raw = archive_path.read_bytes()
    if len(raw) != archive_size or _sha256(raw) != manifest["zip_sha256"]:
        raise ValueError("Frontend ZIP SHA-256 differs from the manifest")
    try:
        with zipfile.ZipFile(io.BytesIO(raw)) as archive:
            seen = set()
            for info in archive.infolist():
                name = info.filename
                if (name != info.orig_filename or name in seen or name not in manifest["files"]
                        or info.is_dir() or stat.S_IFMT(info.external_attr >> 16) not in (0, stat.S_IFREG)
                        or info.flag_bits & 1 or info.compress_type != zipfile.ZIP_DEFLATED
                        or info.file_size != manifest["files"][name]["bytes"]):
                    raise ValueError(f"Unexpected frontend ZIP member: {name}")
                seen.add(name)
            if seen != set(manifest["files"]):
                raise ValueError("Frontend ZIP inventory differs from the manifest")
            yield manifest, archive
    except zipfile.BadZipFile as exc:
        raise ValueError("Invalid frontend ZIP") from exc


def _checked_member(archive, name, pin):
    raw = archive.read(name)
    if len(raw) != pin["bytes"] or _sha256(raw) != pin["sha256"]:
        raise ValueError(f"Frontend file SHA-256 differs from the manifest: {name}")
    return raw


def verify_assets(repo):
    """Return the verified manifest for the current frontend inputs."""
    with _checked_bundle(repo) as (manifest, archive):
        for name, pin in manifest["files"].items():
            _checked_member(archive, name, pin)
        return manifest


def install_assets(repo, root):
    """Install the verified assets into a new build directory in an existing instance root."""
    root = Path(root).absolute()
    if root.resolve(strict=True) != root:
        raise ValueError("Frontend installation requires a canonical instance root")
    _regular(root, directory=True)
    destination = root / "build"
    if destination.exists() or destination.is_symlink():
        raise FileExistsError(f"Frontend build directory already exists: {destination}")
    with _checked_bundle(repo) as (manifest, archive):
        with tempfile.TemporaryDirectory(prefix=".frontends-", dir=root) as temporary:
            stage = Path(temporary)
            if stage.resolve(strict=True).parent != root:
                raise ValueError("Frontend staging escaped the instance root")
            for name, pin in manifest["files"].items():
                target = stage.joinpath(*_asset_parts(name))
                target.parent.mkdir(parents=True, exist_ok=True)
                raw = _checked_member(archive, name, pin)
                with target.open("xb") as stream:
                    stream.write(raw)
            if destination.exists() or destination.is_symlink():
                raise FileExistsError(f"Frontend build directory already exists: {destination}")
            (stage / "build").rename(destination)
        return manifest
