"""Prepare the image inputs for a new portable, source-full preview."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import shutil
import subprocess
import tarfile
import tempfile
from pathlib import Path, PurePosixPath

PLATFORMS = ("linux/amd64", "linux/arm64")
PUBLIC_IMAGES = {
    "MYSQL": "ccr.ccs.tencentyun.com/crmebky_php/mysql@sha256:c7a313f1402365dc91253bdbdb1e74c6a361b036106d8bd25b8ec8e2dd0069f4",
    "REDIS": "ccr.ccs.tencentyun.com/crmebky_php/redis@sha256:6c8f6edc1aa871a76a257ef86a2089d108e64b78d16f45b89f09c6a4ab30d07e",
    "NGINX": "docker.io/library/nginx@sha256:a8b39bd9cf0f83869a2162827a0caf6137ddf759d50a171451b335cecc87d236",
}
# The ARM64 child manifests of the same pinned indexes. An explicit child digest
# keeps the lookup unambiguous if another architecture is already cached locally.
ARM64_PUBLIC_IMAGES = {
    "MYSQL": "ccr.ccs.tencentyun.com/crmebky_php/mysql@sha256:a32313f9a3daa5cbd79eae39c6ce6712c7812a37399aa143e1fedeb040f6402d",
    "REDIS": "ccr.ccs.tencentyun.com/crmebky_php/redis@sha256:846c9510fc2ce6caacaf7b98011cf9b2b364e9efec5d22f5a9875ad6acefe77c",
    "NGINX": "docker.io/library/nginx@sha256:1be286f7dd7d6e6be04f46e8df36d0084b05aa3d7fd3c6ebda3e1eff4e5b665d",
}
BUILD_BASES = {
    "composer:2.10.2": "docker.io/library/composer@sha256:d020706319701a44468968321dccd0fce6620190159a7a9ec195d78e6e971c71",
    "php:8.2-fpm-bookworm": "docker.io/library/php@sha256:4d81f8b24a4b9cbe263093d5e2fbaa49647b1290193488b936f08355933a5060",
}
CONTEXT_PATHS = (".dockerignore", ".gitattributes", "docker/php", "crmeb")
INPUT_LABEL = "marketplace.portable.php-input"


def _output(argv: list[str]) -> str:
    return subprocess.check_output(argv, text=True, encoding="utf-8").strip()


def docker_platform(docker: str) -> str:
    platform = _output([docker, "version", "--format", "{{.Server.Os}}/{{.Server.Arch}}"])
    if platform not in PLATFORMS:
        raise RuntimeError(f"Portable preview requires a Linux amd64 or arm64 Docker server, found {platform}")
    return platform


def _inspect(docker: str, reference: str, platform: str) -> tuple[str, str] | None:
    template = ('{{.Id}}|{{.Os}}/{{.Architecture}}|{{with index .Config "Labels"}}'
                '{{index . "' + INPUT_LABEL + '"}}{{end}}')
    result = subprocess.run([docker, "image", "inspect", "--format", template, reference],
                            capture_output=True, text=True, encoding="utf-8")
    if result.returncode:
        if "No such image" in result.stderr:
            return None
        raise RuntimeError(f"Docker image inspection failed for {reference}: {result.stderr.strip()}")
    image_id, actual, label = result.stdout.strip().split("|", 2)
    if actual != platform or not re.fullmatch(r"sha256:[0-9a-f]{64}", image_id):
        raise RuntimeError(f"Unexpected platform or image ID for {reference}")
    return image_id, label


def _public_image(docker: str, reference: str, platform: str) -> str:
    image = _inspect(docker, reference, platform)
    if image is None:
        subprocess.run([docker, "pull", "--platform", platform, reference], check=True)
        image = _inspect(docker, reference, platform)
    if image is None:
        raise RuntimeError(f"Pinned image is unavailable after pull: {reference}")
    return image[0]


def _unpack_context(git: list[str], head: str, context: Path) -> None:
    with tempfile.TemporaryFile(dir=context.parent) as archive:
        # Archive otherwise applies the machine's checkout line endings, including on Windows.
        subprocess.run([*git, "-c", "core.autocrlf=false", "archive", "--format=tar", head, *CONTEXT_PATHS],
                       stdout=archive, check=True)
        archive.seek(0)
        with tarfile.open(fileobj=archive, mode="r:") as source:
            members = []
            for member in source:
                name = PurePosixPath(member.name)
                if (name.is_absolute() or ".." in name.parts or ":" in member.name or "\\" in member.name
                        or not (member.isdir() or member.isfile())):
                    raise RuntimeError("Unexpected file in the committed PHP build context")
                # Composer installs the locked dependencies inside the image.
                if name.parts[:2] != ("crmeb", "vendor"):
                    members.append(member)
            source.extractall(context, members=members)


def _php_image(repo: Path, docker: str, platform: str) -> str:
    git = [shutil.which("git") or "git", "--no-optional-locks", "-C", str(repo)]
    head = _output([*git, "rev-parse", "--verify", "HEAD"])
    if _output([*git, "status", "--porcelain=v1", "--untracked-files=all", "--", *CONTEXT_PATHS]):
        raise RuntimeError("PHP image inputs differ from the committed checkout")
    dockerfile = _output([*git, "show", f"{head}:docker/php/Dockerfile"])
    external = set()
    stages = set()
    for line in dockerfile.splitlines():
        fields = line.split()
        if fields and fields[0].upper() == "FROM":
            if fields[1] not in stages:
                external.add(fields[1])
            if len(fields) == 4 and fields[2].upper() == "AS":
                stages.add(fields[3])
    if external != set(BUILD_BASES):
        raise RuntimeError("Dockerfile base images changed; review the portable build pins")

    objects = [_output([*git, "rev-parse", "--verify", f"{head}:{path}"])
               for path in CONTEXT_PATHS]
    inputs = {"schema": 3, "context": dict(zip(CONTEXT_PATHS, objects)),
              "platform": platform, "bases": BUILD_BASES}
    fingerprint = hashlib.sha256(json.dumps(inputs, sort_keys=True).encode("utf-8")).hexdigest()
    tag = f"mall-portable/php:{fingerprint}"
    image = _inspect(docker, tag, platform)
    if image is None:
        command = [docker, "build", "--platform", platform, "--progress", "plain",
                   "--tag", tag,
                   "--label", f"{INPUT_LABEL}={fingerprint}"]
        for alias, reference in BUILD_BASES.items():
            command.extend(["--build-context", f"{alias}=docker-image://{reference}"])
        context = Path(tempfile.mkdtemp(prefix=".portable-php-context-", dir=repo))
        try:
            _unpack_context(git, head, context)
            # A local directory lets Docker apply .dockerignore before COPY.
            subprocess.run([*command, "--file", str(context / "docker/php/Dockerfile"), str(context)], check=True)
        finally:
            if context.parent != repo or context.resolve() != context:
                raise RuntimeError("PHP build context path changed before cleanup")
            shutil.rmtree(context)
        image = _inspect(docker, tag, platform)
    if image is None or image[1] != fingerprint:
        raise RuntimeError(f"PHP image does not match the expected portable inputs: {tag}")
    return image[0]


def prepare_images(repo: Path, docker: str, platform: str) -> dict[str, str]:
    """Return the four immutable local image IDs for a portable preview."""
    if platform not in PLATFORMS:
        raise ValueError(f"Unsupported portable image platform: {platform}")
    public_images = ARM64_PUBLIC_IMAGES if platform == "linux/arm64" else PUBLIC_IMAGES
    php = _php_image(repo.resolve(), docker, platform)
    return {"PHP": php, **{role: _public_image(docker, ref, platform) for role, ref in public_images.items()}}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", type=Path, default=Path(__file__).resolve().parents[3])
    parser.add_argument("--docker", default=shutil.which("docker") or "docker")
    parser.add_argument("--php-only", action="store_true")
    args = parser.parse_args()
    platform = docker_platform(args.docker)
    if args.php_only:
        images = {"PHP": _php_image(args.repo.resolve(), args.docker, platform)}
    else:
        images = prepare_images(args.repo, args.docker, platform)
    print(json.dumps(images, sort_keys=True))
