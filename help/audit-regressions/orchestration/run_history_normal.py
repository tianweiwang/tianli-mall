#!/usr/bin/env python3
"""Run the existing normal history sequence once, with exact failed-run retirement.

No new source packet, assertion relaxation, retry, prune, or forced removal.
A retirement receipt never upgrades a failed business run to an acceptance.
"""
import argparse
import ast
import runpy
from urllib.parse import urlsplit
import hashlib
import ipaddress
import json
import os
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from urllib.request import urlopen

# Inputs are set only by configure() after explicit CLI qualification.
# This module is a one-run CLI, never an implicit archived launch on import.
PY = [sys.executable, "-I", "-B", "-X", "utf8"]
DOCKER = ["docker"]
sequence = 0
context_path = None
current_step = "launcher-admission"
active_child = None
last_stage = None
input_pins = {}


def argument_parser():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ("history-source", "freeze", "freeze-sha256", "run-root", "capture",
                 "preview-project", "preview-origin"):
        parser.add_argument("--" + name, required=True)
    parser.add_argument("--reserved-subnet", required=True, action="append")
    return parser


def ordinary(path, exists=True):
    path = Path(path)
    require(path.is_absolute() and ".." not in path.parts, "EXPLICIT_ABSOLUTE_INPUT_PATH")
    require(not any(p.is_symlink() or p.is_junction() for p in (path, *path.parents)),
            "NO_LINK_OR_JUNCTION_INPUT")
    require(path.exists() if exists else not path.exists(), "EXPECTED_INPUT_PATH_STATE")
    return path.resolve(strict=exists)


def hold(path, expected=None):
    path = ordinary(path)
    require(path.is_file(), "REGULAR_PUBLIC_SOURCE_FILE")
    actual = digest(path)
    require(expected is None or actual == expected, "EXACT_PUBLIC_SOURCE_BYTES")
    key = path.as_posix()
    require(key not in input_pins or input_pins[key] == actual, "PUBLIC_INPUT_RETAINED")
    input_pins[key] = actual
    return path


def held_inputs():
    for path, expected in input_pins.items():
        require(digest(ordinary(path)) == expected, "ALL_QUALIFIED_INPUT_BYTES_HELD")


def compose_inputs(raw):
    """Read only the existing pinned Compose shape; no YAML engine or expansion."""
    text = raw.decode("utf-8")
    subnets = re.findall(r"^        - subnet: (\S+)$", text, re.M)
    require(len(subnets) == 1, "ONE_FROZEN_HISTORY_SUBNET")
    subnet = ipaddress.ip_network(subnets[0], strict=True)
    require(subnet.version == 4 and subnet.prefixlen == 28 and subnet.is_private,
            "FROZEN_PRIVATE_HISTORY_SUBNET")
    mysql = re.search(r"^  audit-mysql:\n(.*?)(?=^  audit-redis:)", text, re.M | re.S)
    require(mysql is not None, "EXISTING_MYSQL_COMPOSE_BLOCK")
    command = re.findall(r"^    command: \[mysqld, (.*)\]$", mysql[1], re.M)
    require(len(command) == 1, "ONE_FROZEN_MYSQL_COMMAND")
    options = json.loads('["mysqld", ' + command[0] + ']')
    require(all(type(v) is str for v in options) and options.count("--innodb-use-native-aio=OFF") == 1,
            "FROZEN_MYSQL_NATIVE_AIO_OFF")
    services = re.findall(r"^  (audit-[a-z0-9-]+):$", text, re.M)
    volumes = re.findall(r"^  ([a-z-]+):\n    labels: \*labels$", text.split("\nvolumes:\n", 1)[1], re.M)
    return subnet, options, services, volumes


def configure(args):
    """Qualify all sources and tail consumers without starting Docker or a Run."""
    global PACKET, HOST, FREEZE, RUNS, ARCHIVE, OWNER, ZERO, SOURCE, FREEZE_SHA, HOST_SHA, ZERO_SHA
    global SUBNET, MYSQL_COMMAND, PREVIEW, PREVIEW_ORIGIN, RESERVED_SUBNETS, HOST_MODULE, OWNER_MODULE
    global sequence, context_path, current_step, active_child, last_stage, input_pins, FROZEN, SERVICES, VOLUME_NAMES
    sequence, context_path, current_step, active_child, last_stage, input_pins = 0, None, "launcher-admission", None, None, {}
    require(re.fullmatch("[a-f0-9]{64}", args.freeze_sha256), "EXPLICIT_FREEZE_SHA256")
    PACKET = ordinary(args.history_source)
    require(PACKET.is_dir(), "EXPLICIT_SOURCE_PACKET")
    FREEZE = hold(args.freeze)
    FREEZE_SHA = args.freeze_sha256
    require(digest(FREEZE)["sha256"] == FREEZE_SHA, "EXPLICIT_FREEZE_BYTES")
    FROZEN = load(FREEZE)
    SOURCE = FROZEN["source_sha"]
    require(re.fullmatch("[a-f0-9]{40}", SOURCE)
            and FROZEN["status"] == "CLEAN_SOURCE_FROZEN_ONLY"
            and FROZEN["nonempty_history_runtime"] == "SOURCE_ADOPTED_RUNTIME_NOT_RUN",
            "CURRENT_FROZEN_HISTORY_SCOPE")
    RUNS, ARCHIVE = ordinary(args.run_root, False), ordinary(args.capture, False)
    for target in (RUNS, ARCHIVE):
        require(target.parent.is_dir(), "EXISTING_EXTERNAL_PARENT")
        for protected in (PACKET, ordinary(FROZEN["requested_repo_source"]),
                          ordinary(FROZEN["nonempty_history"]["requested_legacy_repo_source"]),
                          Path(__file__).resolve().parents[3]):
            require(not target.is_relative_to(protected) and not protected.is_relative_to(target),
                    "NEW_OUTPUT_OUTSIDE_ALL_SOURCES")
    require(not RUNS.is_relative_to(ARCHIVE) and not ARCHIVE.is_relative_to(RUNS), "SEPARATE_RUN_AND_CAPTURE")
    source_pins = load(hold(PACKET / "SOURCE-PINS.json"))
    require(source_pins["source_sha"] == SOURCE and len(source_pins["members"]) == 37,
            "SAME_SOURCE_COMPLETE_PACKET")
    for relative, expected in source_pins["members"].items():
        require(not Path(relative).is_absolute() and ".." not in Path(relative).parts, "PACKET_RELATIVE_MEMBER")
        hold(PACKET / relative, expected)
    require(len(FROZEN["artifact_files"]) == 14, "COMPLETE_FROZEN_ARTIFACT14")
    for row in FROZEN["artifact_files"]:
        require(Path(row["path"]).name == row["path"], "DIRECT_CANDIDATE_ARTIFACT")
        hold(PACKET / "candidate" / row["path"], {k: row[k] for k in ("bytes", "sha256")})
    HOST = PACKET / "candidate/ms4-host.py"
    HOST_SHA = digest(HOST)["sha256"]
    sys.dont_write_bytecode = True
    HOST_MODULE = runpy.run_path(str(HOST))
    review = FROZEN["history_source_review"]
    qualified = HOST_MODULE["history_source_review"](
        Path(FROZEN["requested_repo_source"]), SOURCE, review["path"], review["sha256"],
        FROZEN["artifact_files"], FROZEN["nonempty_history"]["control_files"])
    require(qualified == {"review": review, "publication": FROZEN["history_source_publication"]},
            "ORIGINAL_HOST_SOURCE_ADOPTION_JOIN")
    for row in qualified.values():
        hold(row["path"], {k: row[k] for k in ("bytes", "sha256")})
    SUBNET, MYSQL_COMMAND, SERVICES, VOLUME_NAMES = compose_inputs((HOST.parent / "compose.yml").read_bytes())
    require(set(VOLUME_NAMES) == set(HOST_MODULE["VOLUMES"]), "ORIGINAL_SIX_COMPOSE_VOLUMES")
    PREVIEW = args.preview_project
    require(re.fullmatch("mall-preview-[0-9a-f]+", PREVIEW), "EXPLICIT_PROTECTED_PREVIEW_PROJECT")
    url = urlsplit(args.preview_origin)
    require(url.scheme == "http" and url.hostname == "127.0.0.1" and url.port is not None
            and not url.username and not url.password and not url.query and not url.fragment
            and not url.path and args.preview_origin == "http://127.0.0.1:" + str(url.port),
            "EXPLICIT_LOCAL_PREVIEW_ORIGIN")
    PREVIEW_ORIGIN = args.preview_origin
    RESERVED_SUBNETS = [str(ipaddress.ip_network(value, strict=True)) for value in args.reserved_subnet]
    require(len(RESERVED_SUBNETS) == len(set(RESERVED_SUBNETS))
            and all(ipaddress.ip_network(value).version == 4 and not SUBNET.overlaps(ipaddress.ip_network(value))
                    for value in RESERVED_SUBNETS), "EXPLICIT_DISTINCT_RESERVED_SUBNETS")
    OWNER = hold(Path(__file__).with_name("history_ownership_normal.py"))
    actor = hold(Path(__file__).parent.parent / "isolated/ms4-history-ownership-normal.php")
    ZERO = hold(Path(__file__).with_name("verify_history_zero.py"))
    zero = runpy.run_path(str(ZERO))
    require(digest(HOST) in zero["HOST_PINS"] and zero["LEGACY_SHA"] == FROZEN["nonempty_history"]["legacy_source_sha"]
            and zero["PROFILE"] == "current-history-reader-v1" and Path(zero["DOCKER"]).is_file(),
            "TAIL_ZERO_ACCEPTS_THIS_FROZEN_HOST_AND_LEGACY_SOURCE")
    hold(ZERO.with_name("retire_failed_history.py"), zero["HELPERS_PIN"])
    ZERO_SHA = digest(ZERO)["sha256"]
    OWNER_MODULE = runpy.run_path(str(OWNER))
    indexed = {row["path"]: row for row in FROZEN["current_reader"]["source_files"]}
    for name in OWNER_MODULE["DEPENDENCIES"]:
        row = indexed[name]
        hold(Path(FROZEN["requested_repo_source"]) / name, {k: row[k] for k in ("bytes", "sha256")})
    owner_text, actor_text = OWNER.read_text(encoding="utf-8"), actor.read_text(encoding="utf-8")
    require('parser.add_argument("--source", required=True' in owner_text
            and 'expected_source_sha = args.source' in owner_text
            and not any(isinstance(node, ast.Assign) and any(isinstance(t, ast.Name) and t.id == "APP_SOURCE" for t in node.targets)
                        for node in ast.parse(owner_text).body)
            and "const NHECO_APP_SOURCE" not in actor_text
            and "define('NHECO_APP_SOURCE', $expectedSource)" in actor_text
            and OWNER_MODULE["IMAGE"] == HOST_MODULE["PHP"], "TAIL_ACTOR_EXPLICIT_SOURCE_INTERFACE")
    held_inputs()
    return {"history_source": PACKET.as_posix(), "freeze": FREEZE.as_posix(), "freeze_sha256": FREEZE_SHA,
            "source_sha": SOURCE, "run_root": RUNS.as_posix(), "capture": ARCHIVE.as_posix(),
            "preview_project": PREVIEW, "preview_origin": PREVIEW_ORIGIN,
            "reserved_subnets": RESERVED_SUBNETS, "subnet": str(SUBNET), "mysql_command": MYSQL_COMMAND,
            "public_input_pins": input_pins}


def require(condition, label):
    if not condition:
        raise RuntimeError(label)

def now():
    return datetime.now(timezone.utc).isoformat()

def write(file, value):
    with file.open("x", encoding="utf-8") as stream:
        json.dump(value, stream, ensure_ascii=False, indent=2)
        stream.write("\n")
        stream.flush()
        os.fsync(stream.fileno())

def load(file):
    return json.loads(file.read_bytes())

def sha(raw):
    return hashlib.sha256(raw).hexdigest()

def digest(file):
    raw = file.read_bytes()
    return {"bytes":len(raw),"sha256":sha(raw)}

def native(label, argv, capture=None):
    global sequence, current_step, active_child, last_stage
    sequence += 1
    current_step = label
    stage = (capture or ARCHIVE) / f"{sequence:02d}-{label}"
    stage.mkdir(exist_ok=False)
    last_stage = stage
    stdout = stage / "stdout"
    stderr = stage / "stderr"
    argv = [str(arg) for arg in argv]
    with stdout.open("xb") as out, stderr.open("xb") as err:
        child = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=out, stderr=err,
                                 creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        active_child = child
        write(stage/"created.json",{"argv":argv,"pid":child.pid,"started_at":now()})
        print(json.dumps({"stage":label,"child_pid":child.pid,"state":"STARTED"}),flush=True)
        code = child.wait()
        active_child = None
    write(stage/"terminal.json",{"pid":child.pid,"native_exit":code,"wait_returncode":code,
         "stdout":digest(stdout),"stderr":digest(stderr),"finished_at":now()})
    diagnostic = stderr.read_bytes()
    known_wsl_proxy_notice = (
        label in {"ubuntu-all-ipv4-routes","docker-desktop-all-ipv4-routes","aio-headroom"}
        and sha(diagnostic) == "b280c454d6f559e56eb3f5ed39f3ba084b8d1744a3ff7fe016b21ff86dea1aca"
    )
    require(code == 0 and (not diagnostic or known_wsl_proxy_notice), "ORIGINAL_NATIVE_STAGE_FAILED")
    print(json.dumps({"stage":label,"child_pid":child.pid,"native_exit":code}),flush=True)
    return stdout.read_bytes()

def host(stage, *extra, label=None):
    args = ["--binding",FREEZE,"--run-root",RUNS] if stage=="prepare" else ["--context",context_path,*extra]
    held_inputs()
    return json.loads(native(label or stage, [*PY,HOST,stage,*args]))

def status(result, *allowed):
    require(result.get("status") in allowed, "UNEXPECTED_ORIGINAL_STAGE_STATUS")
    return result

def cid(value):
    require(isinstance(value,str) and re.fullmatch(r"[a-f0-9]{64}",value), "EXACT_ACTUAL_CONTAINER_ID")
    return value

def leaf(name):
    return context_path.parent/"evidence"/name

def original_id(proof):
    value=load(leaf(proof+"-created.json"))
    require(value["run_id"]==context_path.parent.name,"CREATED_ACTOR_SAME_RUN")
    return cid(value["id"])

def wait_observe(proof, target):
    target=cid(target)
    waited=native("wait-"+proof,[*DOCKER,"wait",target]).strip()
    require(re.fullmatch(rb"\d+",waited), "ACTUAL_DOCKER_WAIT_OUTPUT")
    seen=host("observe","--proof",proof,"--seconds","0",label="observe-"+proof)
    require(waited==b"0" and seen["status"]=="exited" and seen["id"]==target and
            seen["exit_code"]==0 and seen["pid"]==0 and seen["restart_count"]==0,
            "ACTUAL_SAME_CONTAINER_DID_NOT_NATURALLY_EXIT_ZERO")
    return seen

def finish_registration(proof, target):
    require(original_id(proof)==cid(target),"SAME_REGISTER_HELPER_CID")
    wait_observe(proof,target)
    status(host("finish-helper","--proof",proof,label="finish-"+proof),"SAME_TERMINAL_HELPER_REMOVED_ONLY")

def direct_role(create_name,start_name,proof,create_status,start_status,registration=None):
    created=host(create_name)
    if registration and created.get("status")==registration[0]:
        finish_registration(registration[1],original_id(registration[1]))
    else:
        status(created,create_status)
    target=original_id(proof)
    started=status(host(start_name),start_status)
    require(started["container_id"]==target,"STARTED_SAME_CREATED_ROLE")
    wait_observe(proof,target)

def network_snapshot(label):
    ids=native(label+"-docker-ids",[*DOCKER,"network","ls","-q","--no-trunc"]).decode().splitlines()
    require(ids and len(ids)==len(set(ids)),"NONEMPTY_FRESH_NETWORK_IDS")
    rows=json.loads(native(label+"-docker-ipam",[*DOCKER,"network","inspect",*ids]))
    require({x["Id"] for x in rows}==set(ids),"DOCKER_IPAM_COMPLETE")
    return {n["Id"]:sorted(c.get("Subnet","") for c in (n["IPAM"]["Config"] or [])) for n in rows}

def preview():
    services=native("preview-services",[*DOCKER,"ps","--filter","label=com.docker.compose.project="+PREVIEW,
               "--format","{{.ID}}"]).decode().splitlines()
    require(len(services)==4 and len(set(services))==4,"ACTUAL_PREVIEW_FOUR_RUNNING_SERVICES")
    for route in ["/","/admin/login","/merchant/"]:
        with urlopen(PREVIEW_ORIGIN+route,timeout=8) as response:
            require(response.status==200 and b"<html" in response.read().lower(),"PREVIEW_HTTP_200")
    return set(services)

def admission():
    require(digest(FREEZE)["sha256"]==FREEZE_SHA and digest(HOST)["sha256"]==HOST_SHA and
            digest(ZERO)["sha256"]==ZERO_SHA and not RUNS.exists(),"EXACT_NEW_FROZEN_HISTORY_INPUTS")
    frozen=load(FREEZE)
    require(frozen["status"]=="CLEAN_SOURCE_FROZEN_ONLY" and frozen["source_sha"]==SOURCE and
            frozen["nonempty_history_runtime"]=="SOURCE_ADOPTED_RUNTIME_NOT_RUN","CURRENT_FROZEN_HISTORY_SCOPE")
    require(native("docker-context",[*DOCKER,"context","show"]).strip()==b"desktop-linux","LOCAL_DESKTOP_LINUX_CONTEXT")
    require(not native("no-prior-audit-containers",[*DOCKER,"ps","-aq","--filter","label=tianli.audit=1"]).strip(),
            "PRIOR_AUDIT_CONTAINERS_MUST_BE_DISPOSED")
    before=network_snapshot("before")
    win=["powershell.exe","-NoProfile","-NonInteractive","-Command",
         "$ErrorActionPreference='Stop'; @(Get-NetRoute -AddressFamily IPv4 -IncludeAllCompartments | Select-Object -ExpandProperty DestinationPrefix) | ConvertTo-Json -Compress"]
    windows=json.loads(native("windows-all-ipv4-compartments",win))
    ranges={"windows":[ipaddress.ip_network(v,strict=False) for v in windows]}
    for distro in ["Ubuntu","docker-desktop"]:
        raw=native(distro.lower()+"-all-ipv4-routes",["wsl.exe","-d",distro,"--","ip","-4","route","show","table","all"])
        routes=[]
        for line in raw.decode("utf-8").replace("\x00","").splitlines():
            match=re.match(r"(?:(?:local|broadcast|unreachable|blackhole|prohibit|throw)\s+)?(\d+(?:\.\d+){3}(?:/\d+)?)\s",line)
            if match:
                routes.append(ipaddress.ip_network(match[1],strict=False))
        ranges[distro]=routes
    ranges["docker"]=[n for subnets in before.values() for s in subnets if s and
                       (n:=ipaddress.ip_network(s,strict=False)).version==4]
    require(all(ranges.values()) and all(not SUBNET.overlaps(n) for rows in ranges.values() for n in rows if n.prefixlen),
            "FRESH_SUBNET_CONFLICT_OR_INCOMPLETE_ROUTE_SCOPE")
    require(all(not SUBNET.overlaps(ipaddress.ip_network(s)) for s in RESERVED_SUBNETS),
            "DISTINCT_FORMAL_B_SUBNETS")
    compose=(HOST.parent/"compose.yml").read_text(encoding="utf-8")
    require(str(SUBNET) in compose and compose.count("--innodb-use-native-aio=OFF")==1,"EXACT_COMPOSE_SUBNET_AND_AIO_OFF")
    image_ids=sorted(set(re.findall(r"image: (sha256:[a-f0-9]{64})",compose)))
    require(len(image_ids)==3 and sorted(native("fixed-cached-images",[*DOCKER,"image","inspect","--format","{{.Id}}",*image_ids]).decode().splitlines())==image_ids,
            "ALL_THREE_FROZEN_IMAGES_LOCALLY_PRESENT")
    require(network_snapshot("after")==before,"DOCKER_IPAM_STABLE_DURING_ADMISSION")
    aio=list(map(int,native("aio-headroom",["wsl.exe","-d","docker-desktop","--","cat","/proc/sys/fs/aio-nr","/proc/sys/fs/aio-max-nr"]).split()))
    require(len(aio)==2 and 0<=aio[0]<=aio[1],"ACTUAL_AIO_BASELINE")
    write(ARCHIVE/"ADMISSION.json",{"status":"FRESH_LOCAL_ISOLATED_HISTORY_ADMISSION","network":str(SUBNET),
          "route_scope_counts":{k:len(v) for k,v in ranges.items()},"aio":aio,"per_instance_native_aio":"OFF",
          "freeze_sha256":FREEZE_SHA,"at_utc":now()})

def execute():
    global context_path
    admission()
    preview_ids=preview()
    RUNS.mkdir(exist_ok=False)
    created=status(host("prepare"),"PRIVATE_PREPARE_TERMINAL_ONLY","LIVE_SAME_HANDLE_PRESERVED")
    context_path=Path(created["context"])
    require(context_path.parent.parent==RUNS and context_path.name=="actual-run-context.json",
            "EXACT_ORIGINAL_NEW_CONTEXT_PATH")
    context=load(context_path)
    require(context["run_id"]==context_path.parent.name and context["source_sha"]==SOURCE and
            context["freeze_sha256"]==FREEZE_SHA and Path(context["prep_dir"])==HOST.parent,"EXACT_ORIGINAL_NEW_CONTEXT")
    write(ARCHIVE/"RUN-CONTEXT.json",{"context":context_path.as_posix(),"run_id":context["run_id"],
          "freeze_sha256":FREEZE_SHA,"at_utc":now()})
    if created["status"]=="LIVE_SAME_HANDLE_PRESERVED":
        finish_registration("prepare",created["container_id"])
    status(host("db-up"),"NATIVE_DB_REDIS_READY_ONLY")
    base=context["initial_service_created"]
    mysql=cid(base["audit-mysql"]["id"])
    redis=cid(base["audit-redis"]["id"])
    require(mysql!=redis,"DISTINCT_ORIGINAL_DB_AND_REDIS")
    options=json.loads(native("mysql-options",[*DOCKER,"inspect","--format","{{json .Config.Cmd}}",mysql]))
    require(options==MYSQL_COMMAND and
            options.count("--innodb-use-native-aio=OFF")==1,
            "ACTUAL_MYSQL_FROZEN_OPTIONS")
    require(native("mysql-health",[*DOCKER,"inspect","--format","{{.State.Health.Status}} {{.RestartCount}}",mysql]).strip()==b"healthy 0",
            "ACTUAL_MYSQL_HEALTHY_WITHOUT_RESTART")
    require(native("redis-running",[*DOCKER,"inspect","--format","{{.State.Status}} {{.RestartCount}}",redis]).strip()==b"running 0",
            "ACTUAL_REDIS_WITHOUT_RESTART")
    sql='MYSQL_PWD="$(cat /run/audit-bootstrap/root-password)" exec mysql --protocol=socket --user=root --batch --skip-column-names --execute="SELECT @@GLOBAL.innodb_use_native_aio + 0"'
    require(native("actual-mysql-native-aio",[*DOCKER,"exec",mysql,"sh","-eu","-c",sql]).strip()==b"0",
            "MYSQL_ACTUAL_NATIVE_AIO_OFF")
    init=status(host("initializer-start"),"INITIALIZER_STARTED_ONLY","LIVE_REGISTRATION_SAME_HANDLE_PRESERVED")
    if init["status"]=="LIVE_REGISTRATION_SAME_HANDLE_PRESERVED":
        finish_registration("register-initializer",original_id("register-initializer"))
        init=status(host("initializer-resume"),"SAME_INITIALIZER_STARTED_ONLY")
    require(init["container_id"]==original_id("initializer"),"SAME_ACTUAL_INITIALIZER")
    wait_observe("initializer",init["container_id"])
    direct_role("legacy-files-create","legacy-files-start","legacy-files-bootstrap",
                "LEGACY_FILES_UNSTARTED_SOURCE_BOUND_ONLY","LEGACY_FILES_BOOTSTRAP_STARTED_ONLY",
                ("LIVE_HISTORY_REGISTRATION_SAME_HANDLE_PRESERVED","register-legacy-files-bootstrap"))
    status(host("legacy-files-retire"),"LEGACY_FILES_ONLY_NORMAL_RETIRED")
    direct_role("legacy-producer-create","legacy-producer-start","legacy-history-producer",
                "LEGACY_PRODUCER_UNSTARTED_SOURCE_BOUND_ONLY","LEGACY_HISTORY_PRODUCER_STARTED_ONLY",
                ("LIVE_PRODUCER_REGISTRATION_SAME_HANDLE_PRESERVED","register-legacy-history-producer"))
    made=status(host("driver-create"),"UNSTARTED_MS4_DRIVER_SOURCE_BOUND_ONLY","LIVE_REGISTRATION_SAME_HANDLE_PRESERVED")
    if made["status"]=="LIVE_REGISTRATION_SAME_HANDLE_PRESERVED":
        finish_registration("register-driver",original_id("register-driver"))
        status(host("driver-bind"),"UNSTARTED_MS4_DRIVER_SOURCE_BOUND_ONLY")
    driver=status(host("driver-start"),"MS4_DRIVER_STARTED_ONLY")
    require(driver["container_id"]==original_id("driver"),"SAME_ACTUAL_DRIVER")
    wait_observe("driver",driver["container_id"])
    status(host("driver-retire"),"NONEMPTY_HISTORY_EXACT_NORMAL_ROLE_RETIRED")
    direct_role("current-structure-upgrade-create","current-structure-upgrade-start","current-structure-upgrade",
                "CURRENT_STRUCTURE_UPGRADE_UNSTARTED_ONLY","CURRENT_STRUCTURE_UPGRADE_STARTED_ONLY")
    status(host("current-structure-upgrade-retire"),"NONEMPTY_HISTORY_EXACT_NORMAL_ROLE_RETIRED")
    reader=status(host("current-reader-create"),"LIVE_CURRENT_READER_REGISTRATION_SAME_HANDLE_PRESERVED",
                  "CURRENT_READER_UNSTARTED_SOURCE_BOUND_ONLY")
    if reader["status"]=="LIVE_CURRENT_READER_REGISTRATION_SAME_HANDLE_PRESERVED":
        finish_registration("register-legacy-current-reader",original_id("register-legacy-current-reader"))
        status(host("current-reader-bind"),"CURRENT_READER_UNSTARTED_SOURCE_BOUND_ONLY")
    started=status(host("current-reader-start"),"CURRENT_READER_STARTED_ONLY")
    require(started["container_id"]==original_id("legacy-current-reader"),"SAME_ACTUAL_CURRENT_READER")
    wait_observe("legacy-current-reader",started["container_id"])
    readback=load(leaf("legacy-current-reader-result.json"))
    require(readback["status"]=="VERIFIED_CURRENT_LEGACY_HISTORY_READS_NORMAL_CLOSED","ACTUAL_NORMAL_HISTORY_READBACK")
    status(host("current-reader-retire"),"NONEMPTY_HISTORY_EXACT_NORMAL_ROLE_RETIRED")
    held_inputs()
    ownership=json.loads(native("post-reader-ownership",[*PY,OWNER,"--source",SOURCE,"--history-source",PACKET,"--context",context_path,
                                                      "--output",ARCHIVE/"ownership"]))
    status(ownership,"VERIFIED_POST_READER_ACTOR_NORMAL_RETIRED_ONLY")
    ownership_result=load(ARCHIVE/"ownership/RUNNER-RESULT.json")
    own_cid=cid(ownership["container_id"])
    require(ownership_result["status"]==ownership["status"] and ownership_result["run_id"]==context["run_id"] and
            ownership_result["container_id"]==own_cid and
            own_cid not in ownership_result["resource_after"]["global_ids"],
            "POST_READER_OWNERSHIP_RESULT_AND_ACTUAL_ACTOR_ABSENCE")
    closed=host("history-final-close")
    require(closed==load(leaf("history-final-close.json")) and closed["scope"]=="NONEMPTY_HISTORY_NORMAL_READER_CHAIN_CLOSED"
            and all(not ids for ids in closed["fresh_zero_queries"].values()) and len(closed["fresh_zero_queries"])==6,
            "ACTUAL_HISTORY_ORIGINAL_NORMAL_CLOSE")
    held_inputs()
    independent=json.loads(native("independent-history-zero",[*PY,ZERO,"--profile","current-history-reader-v1",
           "--context",context_path,"--close-result-sha256",digest(leaf("history-final-close.json"))["sha256"],
           "--close-source-sha256",HOST_SHA,"--source-sha256",ZERO_SHA,"--output",ARCHIVE/"independent-zero"]))
    status(independent,"VERIFIED_CURRENT_HISTORY_INDEPENDENT_ZERO")
    require(preview()==preview_ids,"SAME_RUNNING_PREVIEW_AFTER_HISTORY")
    return {"status":"VERIFIED_HISTORY_READER_OWNERSHIP_CLOSE_AND_INDEPENDENT_ZERO","run_id":context["run_id"],
            "reader":digest(leaf("legacy-current-reader-result.json")),"close":digest(leaf("history-final-close.json")),
            "zero":digest(ARCHIVE/"independent-zero/RESULT.json"),"full_acceptance":"INCOMPLETE"}


def failure_context():
    """Use only the context returned by this process's actual prepare invocation."""
    path = context_path
    if path is None and last_stage is not None and last_stage.name.endswith("-prepare"):
        value = load(last_stage / "stdout")
        candidate = value.get("context") or value.get("attempt_context")
        if candidate:
            path = Path(candidate)
    if path is None:
        return None
    path = ordinary(path)
    require(path.parent.parent == RUNS and path.name in {"actual-run-context.json", "preparation-attempt.json"},
            "FAILED_CONTEXT_FROM_THIS_NEW_RUN_ROOT")
    context = load(path)
    require(re.fullmatch(r"\d{14}[a-f0-9]{6}", context["run_id"])
            and context["run_id"] == path.parent.name and context["project"] == "tianli-audit-" + context["run_id"]
            and context["project"] != PREVIEW and context["source_sha"] == SOURCE
            and context["freeze_sha256"] == FREEZE_SHA and Path(context["run_dir"]) == path.parent
            and Path(context["prep_dir"]) == HOST.parent
            and context["repo_dir"] == FROZEN["requested_repo_source"]
            and context["legacy_repo_dir"] == FROZEN["nonempty_history"]["requested_legacy_repo_source"]
            and digest(path.parent / "preparation-source-binding.json") == digest(FREEZE),
            "FAILED_CONTEXT_EXACT_FROZEN_SOURCE_AND_PATHS")
    return context


def original_resources(context):
    """Read existing public identities, never infer a CID from a service name."""
    root = Path(context["run_dir"])
    containers = {}
    records = list(context.get("initial_service_created", {}).values())
    for path in sorted((root / "evidence").glob("*-created.json")):
        value = load(path)
        if isinstance(value, dict) and "id" in value and "labels" in value:
            require(value.get("run_id") == context["run_id"] and value.get("source_sha") == context["source_sha"],
                    "ORIGINAL_CREATED_SAME_RUN_SOURCE")
            records.append(value)
    actor_path = ARCHIVE / "ownership/source/created.json"
    if actor_path.is_file():
        actor = load(actor_path)
        require(actor.get("run_id") == context["run_id"] and actor.get("source_sha") == context["source_sha"],
                "ORIGINAL_OWNERSHIP_CREATED_SAME_RUN_SOURCE")
        records.append(actor)
    for row in records:
        target = cid(row["id"])
        if target in containers:
            HOST_MODULE["same_created"](containers[target], row)
        containers[target] = row
    volumes = context.get("initial_volumes")
    if volumes is None:
        path = root / "volumes.jsonl"
        volumes = [json.loads(line) for line in path.read_bytes().splitlines()] if path.is_file() else []
    network = context.get("initial_network")
    if network is None and (root / "network.json").is_file():
        network = load(root / "network.json")
    return {"containers": containers, "volumes": {v["name"]: v for v in volumes},
            "networks": {network["Id"]: network} if network else {}}


def exact_labels(context, labels):
    require(isinstance(labels, dict) and labels.get("tianli.audit") == "1"
            and labels.get("tianli.audit.run") == context["run_id"]
            and labels.get("com.docker.compose.project") == context["project"], "RETIRE_EXACT_RUN_PROJECT_LABELS")


def container_identity(context, original, fresh):
    role = original["labels"]["com.docker.compose.service"]
    require(role in SERVICES or role == OWNER_MODULE["SERVICE"], "RETIRE_EXISTING_FIXED_ROLE_ONLY")
    exact_labels(context, fresh["labels"])
    HOST_MODULE["same_created"](original, fresh)
    if role == OWNER_MODULE["SERVICE"]:
        OWNER_MODULE["actor_identity"](context, fresh)
        OWNER_MODULE["actor_mounts"]({"repo": Path(context["repo_dir"]), "profile": Path(context["prep_dir"]),
                                      "root": Path(context["run_dir"]), "context": context},
                                     ARCHIVE / "ownership/source", ARCHIVE / "ownership/evidence", fresh)
    else:
        HOST_MODULE["owned"](context, fresh, role)
    # The original full immutable mount array has already matched. Also constrain
    # every bind to the actual Run/source roots, including partial preparation.
    binds = {"/workspace": context["repo_dir"], "/source": context["repo_dir"],
             "/legacy-source": context["legacy_repo_dir"], "/legacy-workspace": context["legacy_repo_dir"],
             "/run/ms4-prep": context["prep_dir"], "/run/nonempty": context["prep_dir"],
             "/run/audit": context["run_dir"], "/run/post-reader": (ARCHIVE / "ownership/source").as_posix(),
             "/run/post-reader-output": (ARCHIVE / "ownership/evidence").as_posix()}
    def canonical(value):
        value = value.replace("\\", "/").rstrip("/")
        match = re.fullmatch(r"([A-Za-z]):(/.*)", value)
        return "/run/desktop/mnt/host/" + match[1].lower() + match[2] if match else value
    for mount in fresh["mounts"]:
        if mount["Type"] == "bind":
            require(mount["Destination"] in binds and canonical(mount["Source"]) == canonical(binds[mount["Destination"]]),
                    "RETIRE_ONLY_ORIGINAL_RUN_SOURCE_BINDS")
        elif mount["Type"] == "volume":
            require(mount["Name"] in {context["project"] + "_" + name for name in VOLUME_NAMES},
                    "RETIRE_ONLY_ORIGINAL_SIX_VOLUMES")
        else:
            require(mount["Type"] == "tmpfs", "RETIRE_ONLY_EXISTING_MOUNT_TYPES")


GLOBAL_FORMAT = ('{"id":{{json .Id}},"name":{{json .Name}},"image":{{json .Image}},'
                 '"created_at":{{json .Created}},"labels":{{json .Config.Labels}},"mounts":{{json .Mounts}},'
                 '"networks":{{json .NetworkSettings.Networks}},"network_mode":{{json .HostConfig.NetworkMode}}}')
VOLUME_FORMAT = ('{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}},'
                 '"driver":{{json .Driver}},"scope":{{json .Scope}},"mountpoint":{{json .Mountpoint}}}')
NETWORK_FORMAT = ('{"Id":{{json .Id}},"Name":{{json .Name}},"Internal":{{json .Internal}},'
                  '"Created":{{json .Created}},"Driver":{{json .Driver}},"Scope":{{json .Scope}},'
                  '"Labels":{{json .Labels}},"Containers":{{json .Containers}},"IPAM":{{json .IPAM}}}')


def stable_container(row):
    # Docker may reorder Mounts. Compare the complete multiset, including every
    # field and any duplicate entry, rather than overwriting by Destination.
    return {**row, "mounts": sorted(row["mounts"], key=lambda value: json.dumps(value, sort_keys=True))}


def non_target_resources(snapshot):
    return {kind: {key: value for key, value in snapshot[scope].items() if key not in snapshot["targets"][kind]}
            for kind, scope in (("containers", "global"), ("volumes", "global_volumes"), ("networks", "global_networks"))}


def retirement_snapshot(context, run):
    """Fresh global references plus both separate label queries; no Config.Env."""
    targets, label_sets = {}, {}
    for kind, prefix in (("containers", ["ps", "-aq", "--no-trunc"]),
                         ("volumes", ["volume", "ls", "-q"]), ("networks", ["network", "ls", "-q", "--no-trunc"])):
        pair = [run("retire-" + kind + "-" + suffix, [*DOCKER, *prefix, "--filter", label]).decode().splitlines()
                for suffix, label in (("project", "label=com.docker.compose.project=" + context["project"]),
                                      ("run", "label=tianli.audit.run=" + context["run_id"]))]
        require(all(len(ids) == len(set(ids)) for ids in pair), "RETIRE_UNIQUE_LABEL_QUERY_RESULTS")
        label_sets[kind] = {"project": sorted(pair[0]), "run": sorted(pair[1])}
        targets[kind] = sorted(set(pair[0]) | set(pair[1]))
    all_ids = run("retire-global-ids", [*DOCKER, "ps", "-aq", "--no-trunc"]).decode().splitlines()
    require(len(all_ids) == len(set(all_ids)) and all(re.fullmatch("[a-f0-9]{64}", value) for value in all_ids),
            "RETIRE_COMPLETE_GLOBAL_CIDS")
    rows = [json.loads(line) for line in run("retire-global-references", [*DOCKER, "inspect", "--format", GLOBAL_FORMAT, *all_ids]).splitlines()] if all_ids else []
    require({row["id"] for row in rows} == set(all_ids), "RETIRE_COMPLETE_GLOBAL_REFERENCES")
    require(set(run("retire-global-ids-held", [*DOCKER, "ps", "-aq", "--no-trunc"]).decode().splitlines()) == set(all_ids),
            "RETIRE_STABLE_GLOBAL_CIDS")
    public = {"targets": targets, "label_sets": label_sets, "global": {row["id"]: stable_container(row) for row in rows}}
    for kind, noun, field, template in (("volumes", "volume", "name", VOLUME_FORMAT),
                                         ("networks", "network", "Id", NETWORK_FORMAT)):
        query = [*DOCKER, noun, "ls", "-q"] + (["--no-trunc"] if noun == "network" else [])
        names = run("retire-global-" + kind, query).decode().splitlines()
        require(len(names) == len(set(names)), "RETIRE_COMPLETE_GLOBAL_RESOURCE_NAMES")
        values = [json.loads(line) for line in run("retire-global-" + kind + "-identities",
                  [*DOCKER, noun, "inspect", "--format", template, *names]).splitlines()] if names else []
        require(len(values) == len(names) and {row[field] for row in values} == set(names)
                and set(run("retire-global-" + kind + "-held", query).decode().splitlines()) == set(names),
                "RETIRE_ALL_GLOBAL_RESOURCE_IDENTITIES_HELD")
        public["global_" + kind] = {row[field]: row for row in values}
        public[kind] = {target: public["global_" + kind][target] for target in targets[kind]}
    public["containers"] = {target: json.loads(run("retire-containers-identity", [*DOCKER, "inspect", "--format",
                             '{"user":{{json .Config.User}},' + HOST_MODULE["INSPECT"][1:], target])) for target in targets["containers"]}
    return public


def retirement_plan(context, originals, snapshot):
    """Pure eligibility. Missing originals are reported, never guessed from labels."""
    plan = {"containers": [], "volumes": [], "networks": [], "retained": []}
    for kind in ("containers", "volumes", "networks"):
        for target, fresh in snapshot[kind].items():
            original = originals[kind].get(target)
            if original is None:
                plan["retained"].append({"kind": kind, "id": target, "reason": "NO_ORIGINAL_IDENTITY"})
                continue
            try:
                if kind == "containers":
                    container_identity(context, original, fresh)
                else:
                    exact_labels(context, fresh["labels" if kind == "volumes" else "Labels"])
                    require(all(fresh[key] == value for key, value in original.items()), "RETIRE_ORIGINAL_RESOURCE_IDENTITY")
                    if kind == "volumes":
                        require(target in {context["project"] + "_" + name for name in VOLUME_NAMES}, "RETIRE_DECLARED_VOLUME")
                    else:
                        require(fresh["Internal"] is True and fresh["Name"] == context["project"] + "_isolation"
                                and [item.get("Subnet") for item in fresh["IPAM"]["Config"]] == [str(SUBNET)],
                                "RETIRE_FROZEN_INTERNAL_SUBNET")
                plan[kind].append(target)
            except (RuntimeError, KeyError, TypeError) as error:
                plan["retained"].append({"kind": kind, "id": target, "reason": public_error(error)})
    return plan


def no_foreign_consumers(snapshot, allowed):
    volumes, networks = snapshot["volumes"], snapshot["networks"]
    names = set(networks) | {row["Name"] for row in networks.values()}
    physical = [row["mountpoint"].rstrip("/") for row in volumes.values()]
    require(all(root.startswith("/") and len(root) > 1 for root in physical), "RETIRE_ABSOLUTE_PHYSICAL_VOLUME_ROOTS")
    def overlaps(left, right):
        return left == right or left.startswith(right.rstrip("/") + "/") or right.startswith(left.rstrip("/") + "/")
    for name, volume in snapshot["global_volumes"].items():
        root = volume["mountpoint"].rstrip("/") or "/"
        require(volume["mountpoint"].startswith("/"), "RETIRE_KNOWN_GLOBAL_VOLUME_PHYSICAL_ROOT")
        require(name in volumes or not any(overlaps(root, own) for own in physical), "RETIRE_NO_OTHER_VOLUME_PHYSICAL_ALIAS")
    aliases = set(allowed) | {row["name"].lstrip("/") for key, row in snapshot["global"].items() if key in allowed}
    for key, row in snapshot["global"].items():
        touched = bool(names & set(row["networks"])) or any(value.get("NetworkID") in names for value in row["networks"].values())
        mode = row["network_mode"]
        touched = touched or mode in names or (mode.startswith("container:") and any(mode[10:] == alias or
                   (len(mode[10:]) >= 12 and alias.startswith(mode[10:])) for alias in aliases))
        for mount in row["mounts"]:
            require(mount.get("Type") in {"bind", "volume", "tmpfs"}, "RETIRE_KNOWN_GLOBAL_MOUNT_TYPE")
            if mount["Type"] != "tmpfs":
                source = mount.get("Source", "")
                # Docker may report a volume's name as Source. Resolve only an
                # exact name against this same complete volume-inspect snapshot.
                if mount["Type"] == "volume" and source == mount.get("Name"):
                    volume = snapshot["global_volumes"].get(source)
                    require(volume is not None and volume["name"] == source, "RETIRE_KNOWN_NAMED_VOLUME_IDENTITY")
                    source = volume["mountpoint"]
                require(isinstance(source, str) and (source.startswith("/") or re.match(r"[A-Za-z]:[/\\]", source)),
                        "RETIRE_KNOWN_GLOBAL_MOUNT_SOURCE")
                source = source.rstrip("/") or "/"
                touched = touched or mount.get("Name") in volumes or any(overlaps(source, root) for root in physical)
        require(not touched or key in allowed, "RETIRE_NO_FOREIGN_OR_UNPROVEN_RESOURCE_CONSUMER")
    require(all(set(row["Containers"] or {}) <= allowed for row in networks.values()), "RETIRE_ONLY_PROVEN_NETWORK_ENDPOINTS")


def public_error(error):
    value = str(error)
    return value if isinstance(error, RuntimeError) and re.fullmatch("[A-Z0-9_]+", value) else type(error).__name__


def retire_failed_run(failure, *, output=None):
    """Retain the original failure; best effort here can only report retirement."""
    output = ARCHIVE / "retirement" if output is None else ordinary(output, False)
    require(output.parent == ARCHIVE and re.fullmatch(r"retirement(?:-[a-z0-9-]+)?", output.name),
            "RETIRE_NEW_CAPTURE_WITHIN_ORIGINAL_ARCHIVE")
    output.mkdir()
    result = {"status": "FAILED_RUN_RESOURCES_RETAINED", "native_exit": 1,
              "original_failure": digest(ARCHIVE / "FAILURE.json"), "removed": {"containers": [], "volumes": [], "networks": []},
              "full_acceptance": "INCOMPLETE"}
    run = lambda label, argv: native(label, argv, capture=output)
    try:
        require(active_child is None or active_child.poll() is not None, "LIVE_HOST_CHILD_NO_RESOURCE_MUTATION")
        context = failure_context()
        require(context is not None, "NO_ACTUAL_RUN_CONTEXT_NO_RESOURCE_MUTATION")
        held_inputs()
        originals = original_resources(context)
        before = retirement_snapshot(context, run)
        write(output / "before.json", before)
        plan = retirement_plan(context, originals, before)
        write(output / "PLAN.json", plan)
        result["retained"] = plan["retained"]
        targets = set(plan["containers"])
        no_foreign_consumers(before, targets)
        non_target = non_target_resources(before)
        # The original sequence already requires the protected preview. Save its
        # actual immutable public IDs and compare the entire non-target set too.
        protected_ids = set(run("retire-preview-ids", [*DOCKER, "ps", "-aq", "--no-trunc", "--filter",
                                "label=com.docker.compose.project=" + PREVIEW]).decode().splitlines())
        require(len(protected_ids) == 4 and protected_ids <= set(non_target["containers"]), "RETIRE_PROTECTED_PREVIEW_OUTSIDE_RUN")
        for target in plan["containers"]:
            current = retirement_snapshot(context, run)
            require(non_target_resources(current) == non_target, "RETIRE_ALL_NON_TARGET_IDENTITIES_HELD")
            no_foreign_consumers(current, set(current["targets"]["containers"]) & targets)
            facts = current["containers"][target]
            container_identity(context, originals["containers"][target], facts)
            require(facts["status"] in {"created", "running", "exited"}, "RETIRE_NORMAL_CONTAINER_STATE")
            if facts["status"] == "running":
                run("retire-normal-stop", [*DOCKER, "stop", "--timeout=-1", target])
                waited = run("retire-native-wait", [*DOCKER, "wait", target]).strip()
                require(re.fullmatch(rb"\d+", waited), "RETIRE_ACTUAL_NATIVE_WAIT")
            fresh = json.loads(run("retire-terminal", [*DOCKER, "inspect", "--format",
                                  '{"user":{{json .Config.User}},' + HOST_MODULE["INSPECT"][1:], target]))
            container_identity(context, originals["containers"][target], fresh)
            require(fresh["status"] in {"created", "exited"} and fresh["pid"] == 0, "RETIRE_NATIVE_CONTAINER_TERMINAL")
            if facts["status"] == "running":
                require(int(waited) == fresh["exit_code"], "RETIRE_WAIT_AND_TERMINAL_SAME_EXIT")
            run("retire-exact-container", [*DOCKER, "rm", target])
            result["removed"]["containers"].append(target)
        for kind in ("volumes", "networks"):
            for target in plan[kind]:
                current = retirement_snapshot(context, run)
                require(non_target_resources(current) == non_target, "RETIRE_ALL_NON_TARGET_IDENTITIES_HELD")
                no_foreign_consumers(current, set())
                require(target in retirement_plan(context, originals, current)[kind], "RETIRE_RESOURCE_IDENTITY_HELD")
                run("retire-exact-" + kind, [*DOCKER, "volume" if kind == "volumes" else "network", "rm", target])
                result["removed"][kind].append(target)
        after = retirement_snapshot(context, run)
        write(output / "after.json", after)
        result["remaining"] = after["targets"]
        require(non_target_resources(after) == non_target, "RETIRE_ALL_NON_TARGET_CONTAINERS_VOLUMES_NETWORKS_HELD")
        require(all(not ids for ids in after["targets"].values()), "RETIRE_EXACT_RUN_THREE_RESOURCE_CLASSES_ZERO")
        result.update(status="FAILED_RUN_EXACT_RESOURCES_RETIRED", native_exit=0)
    except BaseException as error:
        result["error"] = public_error(error)
        # A failed delete/unknown identity must not conceal remaining resources.
        if "context" in locals() and context is not None and (active_child is None or active_child.poll() is not None):
            try:
                remaining = retirement_snapshot(context, run)
                write(output / "remaining.json", remaining)
                result["remaining"] = remaining["targets"]
            except BaseException as observation:
                result["remaining_observation_error"] = public_error(observation)
    write(output / "RESULT.json", result)
    return result


def main():
    args = argument_parser().parse_args()
    # Qualification failure has not created any runtime/capture and never deletes.
    inputs = configure(args)
    ARCHIVE.mkdir()
    write(ARCHIVE / "inputs.json", inputs)
    write(ARCHIVE / "launcher.created.json", {"pid": os.getpid(), "started_at": now(), "source": digest(Path(__file__))})
    try:
        result = execute()
        write(ARCHIVE / "RESULT.json", result)
        code = 0
    except BaseException as error:
        result = {"status": "ACTUAL_HISTORY_RUN_INCOMPLETE_STATE_PRESERVED", "stage": current_step,
                  "error": public_error(error), "context": context_path.as_posix() if context_path else None,
                  "original_native": load(last_stage / "terminal.json") if last_stage and (last_stage / "terminal.json").is_file() else None,
                  "original_capture": last_stage.as_posix() if last_stage else None, "full_acceptance": "INCOMPLETE"}
        write(ARCHIVE / "FAILURE.json", result)
        code = 1
        try:
            retirement = retire_failed_run(result)
            result = {**result, "retirement": {"status": retirement["status"], "native_exit": retirement["native_exit"],
                                               "result": digest(ARCHIVE / "retirement/RESULT.json")}}
        except BaseException as cleanup_error:
            result = {**result, "retirement_error": public_error(cleanup_error)}
    write(ARCHIVE / "launcher.terminal.json", {"pid": os.getpid(), "exit": code, "finished_at": now(), **result})
    print(json.dumps(result, ensure_ascii=False), flush=True)
    return code


if __name__ == "__main__":
    raise SystemExit(main())
