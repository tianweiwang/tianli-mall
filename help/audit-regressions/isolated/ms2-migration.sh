#!/usr/bin/env bash
set -euo pipefail
# Fresh normal-only migration. No generic runner, application or service fault loop.
m2_source='' m2_output=''
while [[ $# -gt 0 ]]; do
    case "$1" in
        --source) [[ $# -ge 2 && -z "$m2_source" ]] || exit 2; m2_source="$2"; shift 2 ;;
        --output) [[ $# -ge 2 && -z "$m2_output" ]] || exit 2; m2_output="$2"; shift 2 ;;
        *) printf 'Usage: ms2-migration.sh --source CLEAN_HEAD --output NEW_DIRECTORY\n' >&2; exit 2 ;;
    esac
done
[[ "$m2_source" =~ ^[a-f0-9]{40}$ && -n "$m2_output" ]] || exit 2
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/bootstrap.sh"
export AUDIT_GUARD_MODE=read-only AUDIT_MILESTONE=MS2 AUDIT_PHASE=normal-only
m2_git() { git -c "safe.directory=$(audit_native_path "$audit_repo")" -C "$(audit_native_path "$audit_repo")" "$@"; }
m2_clean() {
    local actual_head actual_dirty
    actual_head="$(m2_git rev-parse HEAD)" || return 1
    actual_dirty="$(m2_git status --porcelain --untracked-files="$1")" || return 1
    [[ "$actual_head" == "$m2_source" && -z "$actual_dirty" ]] || return 1
    [[ ! -e "$audit_repo/crmeb/.env" && ! -L "$audit_repo/crmeb/.env" && ! -e "$audit_repo/crmeb/public/install.lock" && ! -L "$audit_repo/crmeb/public/install.lock" ]] || return 1
}
m2_clean normal || { printf 'Current source must be the supplied complete clean HEAD\n' >&2; exit 2; }
[[ ! -e "$m2_output" && ! -L "$m2_output" ]] || { printf 'Output must be a new directory\n' >&2; exit 2; }
umask 077
mkdir -m 700 -p -- "$m2_output"
m2_output="$(cd "$m2_output" && pwd -P)"
m2_run="$(date -u +%Y%m%d%H%M%S)$(openssl rand -hex 3)"
audit_context "$m2_run"
[[ "$AUDIT_SOURCE_SHA" == "$m2_source" && ! -e "$audit_run_path" && ! -L "$audit_run_path" ]] || exit 2
mkdir -m 700 -p -- "$audit_run_path"
m2_image='sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a'
# The unchanged teardown uses the original tag: verify its same pinned identity.
[[ "$(docker image inspect --format '{{.Id}}' tianli/crmeb-php:acceptance)" == "$m2_image" ]] || exit 2
for m2_needed in "$m2_image" ccr.ccs.tencentyun.com/crmebky_php/mysql:v8.0.45 ccr.ccs.tencentyun.com/crmebky_php/redis:v8.6.1; do docker image inspect "$m2_needed" >/dev/null; done
audit_compose() {
    docker compose --project-name "$AUDIT_PROJECT" --file "$(audit_native_path "$audit_script_dir/compose.yml")" --file "$(audit_native_path "$audit_script_dir/compose.ms2-migration.yml")" --profile ms2-migration "$@"
}
m2_preserve() {
    printf 'RUN_ID=%s\nRUN_DIR=%s\nPRESERVED=1\n' "$AUDIT_RUN_ID" "$AUDIT_RUN_DIR"
    printf '{"schema":1,"status":"PRESERVED_UNKNOWN_OR_LIVE","run_id":"%s","source_sha":"%s","stage":"%s","Task2_complete":false}\n' "$AUDIT_RUN_ID" "$m2_source" "$1" > "$m2_output/orchestration.json"
    exit 3
}
m2_stage() {
    local name="$1"; shift
    if "$@" > "$m2_output/$name.stdout" 2> "$m2_output/$name.stderr"; then
        printf '{"stage":"%s","actual_native":0}\n' "$name" >> "$m2_output/stages.jsonl"
    else
        local native=$?
        printf '{"stage":"%s","actual_native":%s}\n' "$name" "$native" >> "$m2_output/stages.jsonl"
        m2_preserve "$name"
    fi
}
m2_stage compose-config audit_compose config --quiet
m2_stage prepare audit_compose run --rm --no-deps -T audit-bootstrap help/audit-regressions/isolated/ms2-migration.php prepare
m2_stage up audit_compose up -d --wait --wait-timeout 90 audit-mysql audit-redis
m2_stage register-before-finite audit_register
m2_stage create audit_compose create --no-recreate --no-build --pull never audit-ms2-migration
m2_stage register-created audit_register
m2_finite="$(audit_compose ps -aq audit-ms2-migration)"
[[ "$m2_finite" =~ ^[a-f0-9]{64}$ ]] || m2_preserve finite-id
m2_state="$(docker inspect --format '{{.Id}} {{.State.Status}} {{.RestartCount}}' "$m2_finite")" || m2_preserve created-inspect
[[ "$m2_state" == "$m2_finite created 0" ]] || m2_preserve created-state
m2_clean no || m2_preserve source-before-binding
# Only public Docker inspect fields; never .Config.Env or raw logs.
docker inspect --format '{"run_id":"'"$AUDIT_RUN_ID"'","source_sha":"'"$m2_source"'","id":{{json .Id}},"image":{{json .Image}},"hostname":{{json .Config.Hostname}},"labels":{{json .Config.Labels}},"mounts":{{json .Mounts}},"tmpfs":{{json .HostConfig.Tmpfs}},"read_only":{{json .HostConfig.ReadonlyRootfs}},"privileged":{{json .HostConfig.Privileged}},"network_mode":{{json .HostConfig.NetworkMode}},"status":{{json .State.Status}},"command":{{json .Config.Cmd}},"entrypoint":{{json .Config.Entrypoint}},"workdir":{{json .Config.WorkingDir}},"restart_count":{{json .RestartCount}},"created_at":{{json .Created}}}' "$m2_finite" > "$audit_run_path/evidence/ms2-migration-container-created.json" || m2_preserve save-created
m2_bind_php="$(cat <<'PHP'
ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');
try {
 require '/workspace/help/audit-regressions/isolated/ms2-migration.php';
 $run=$argv[1];$sha=$argv[2];$repo=$argv[3];$runPath=$argv[4];
 $manifest=json_decode(file_get_contents('/run/audit/manifest.json'),true,64,JSON_THROW_ON_ERROR);
 $created=json_decode(file_get_contents('/run/audit/evidence/ms2-migration-container-created.json'),true,64,JSON_THROW_ON_ERROR);
 if(($manifest['run_id']??null)!==$run||($manifest['commit_sha']??null)!==$sha||($manifest['guard_mode']??null)!=='read-only'||($created['run_id']??null)!==$run||($created['source_sha']??null)!==$sha||($created['id']??null)!==($manifest['services']['audit-ms2-migration']['id']??null)||($created['status']??null)!=='created'||($created['restart_count']??null)!==0)throw new RuntimeException('PUBLIC_SOURCE_BINDING');
 $files=[];foreach(m2m_sourcePaths() as $relative){$path='/workspace/'.$relative;if(!is_file($path)||is_link($path)||!is_file('/source/'.$relative)||hash_file('sha256',$path)!==hash_file('sha256','/source/'.$relative))throw new RuntimeException('SOURCE_FILE');$files[]=['path'=>$relative,'bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path)];}
 $entry=hash_file('sha256','/workspace/help/audit-regressions/isolated/ms2-migration.php');
 $binding=['schema'=>1,'run_id'=>$run,'source_sha'=>$sha,'requested_repo_source'=>$repo,'requested_run_source'=>$runPath,'source_files'=>$files,'entry_sha256'=>$entry,'cli_sha256'=>hash_file('sha256','/workspace/help/migrations/marketplace-ms2.php'),'fixture_definition_sha256'=>$entry];
 $bytes=json_encode($binding,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";$h=fopen('/run/audit/evidence/ms2-migration-source-binding.json','xb');if(!is_resource($h))throw new RuntimeException('SOURCE_RECEIPT');try{if(fwrite($h,$bytes)!==strlen($bytes)||!fflush($h))throw new RuntimeException('SOURCE_WRITE');}finally{fclose($h);}
 echo json_encode(['status'=>'SOURCE_BOUND','actual_files'=>count($files),'source_sha'=>$sha],JSON_THROW_ON_ERROR),"\n";
}catch(Throwable $error){fwrite(STDERR,get_class($error)."\n");exit(1);}
PHP
)"
m2_stage bind-source audit_compose run --rm --no-deps -T audit-bootstrap -r "$m2_bind_php" "$AUDIT_RUN_ID" "$m2_source" "$AUDIT_REPO_DIR" "$AUDIT_RUN_DIR"
m2_clean no || m2_preserve source-before-start
m2_stage start docker start "$m2_finite"
m2_deadline=$((SECONDS+900))
m2_native='' m2_first_live=0
while :; do
    m2_state="$(docker inspect --format '{{.Id}} {{.State.Status}} {{.State.ExitCode}} {{.RestartCount}}' "$m2_finite")" || m2_preserve finite-observation
    read -r m2_actual_id m2_actual_state m2_actual_exit m2_restarts <<< "$m2_state"
    [[ "$m2_actual_id" == "$m2_finite" && "$m2_restarts" == 0 && "$m2_actual_exit" =~ ^[0-9]+$ ]] || m2_preserve finite-identity
    if [[ "$m2_actual_state" == running && "$m2_first_live" == 0 ]]; then
        docker inspect --format '{"id":{{json .Id}},"image":{{json .Image}},"status":{{json .State.Status}},"pid":{{json .State.Pid}},"started_at":{{json .State.StartedAt}},"restart_count":{{json .RestartCount}}}' "$m2_finite" > "$audit_run_path/evidence/ms2-migration-first-live.json" || m2_preserve first-live
        m2_first_live=1
    fi
    if [[ "$m2_actual_state" == exited ]]; then m2_native="$m2_actual_exit"; break; fi
    [[ "$m2_actual_state" == running || "$m2_actual_state" == created ]] || m2_preserve unknown-finite-state
    (( SECONDS < m2_deadline )) || m2_preserve finite-still-live
    sleep 1
done
docker inspect --format '{"run_id":"'"$AUDIT_RUN_ID"'","source_sha":"'"$m2_source"'","id":{{json .Id}},"image":{{json .Image}},"status":{{json .State.Status}},"exit_code":{{json .State.ExitCode}},"pid":{{json .State.Pid}},"started_at":{{json .State.StartedAt}},"finished_at":{{json .State.FinishedAt}},"restart_count":{{json .RestartCount}}}' "$m2_finite" > "$audit_run_path/evidence/ms2-migration-terminal.json" || m2_preserve terminal-receipt
m2_verified=0
m2_verify_php="$(cat <<'PHP'
ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');
try {
 $run=$argv[1];$sha=$argv[2];$id=$argv[3];$native=$argv[4];
 $terminal=json_decode(file_get_contents('/run/audit/evidence/ms2-migration-terminal.json'),true,64,JSON_THROW_ON_ERROR);
 $result=json_decode(file_get_contents('/run/audit/evidence/ms2-migration-normal.json'),true,128,JSON_THROW_ON_ERROR);
 $source=json_decode(file_get_contents('/run/audit/evidence/ms2-migration-source-binding.json'),true,64,JSON_THROW_ON_ERROR);
 if($native!=='0'||($terminal['id']??null)!==$id||($terminal['run_id']??null)!==$run||($terminal['source_sha']??null)!==$sha||($terminal['status']??null)!=='exited'||($terminal['exit_code']??null)!==0||($terminal['restart_count']??null)!==0||($result['status']??null)!=='VERIFIED_NORMAL_FOUR_COMMANDS_ONLY'||($result['scope']??null)!=='NORMAL_FOUR_COMMANDS_ONLY'||($result['run_id']??null)!==$run||($result['source_sha']??null)!==$sha||($result['native_exit']??null)!==0||($result['private_cleanup_verified']??null)!==true||($result['Task2_complete']??null)!==false||($result['binding']['container_id']??null)!==$id||($result['binding']['entry_namespace_pid']??null)!==1||($result['binding']['entry_sha256']??null)!==($source['entry_sha256']??null))throw new RuntimeException('FINITE_NORMAL_RECEIPT');
 $counts=$result['actual_command_counts']??null;if(!is_array($counts)||($counts['MS1_apply']??null)!==1||($counts['MS2_processes']??null)!==9||($counts['total_processes']??null)!==10||($counts['MS2']??null)!==['apply-structure'=>2,'backfill'=>2,'check'=>3,'enforce'=>2]||count($result['children']??[])!==10)throw new RuntimeException('ACTUAL_CHILD_COUNTS');$pids=[];foreach($result['children'] as $child){if(($child['native_exit']??null)!==0||($child['stderr_bytes']??null)!==0||!is_int($child['pid']??null)||$child['pid']<=0||isset($pids[$child['pid']]))throw new RuntimeException('ACTUAL_NATIVE_CHILD');$pids[$child['pid']]=true;}
 $reports=glob('/run/audit/evidence/ms2-migration-normal-only-*.jsonl');if(!is_array($reports)||count($reports)!==1)throw new RuntimeException('FORMAL_RESULT_FILE');$lines=file($reports[0],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);if(count($lines)!==8)throw new RuntimeException('FORMAL_RESULT_COUNT');$seen=[];foreach($lines as $line){$row=json_decode($line,true,64,JSON_THROW_ON_ERROR);$case=$row['case_id']??'';if(isset($seen[$case])||($row['run_id']??null)!==$run||($row['commit_sha']??null)!==$sha||($row['milestone']??null)!=='MS2'||($row['layer']??null)!=='INTEGRATION'||($row['phase']??null)!=='normal-only'||($row['status']??null)!==($case==='T2M_NORMAL'?'PASS':'NOT RUN'))throw new RuntimeException('FORMAL_SCOPED_RESULT');$seen[$case]=true;}$cases=array_keys($seen);sort($cases,SORT_STRING);if($cases!==['G01','G02','G03','G04','G05','G06','G07','T2M_NORMAL'])throw new RuntimeException('FORMAL_SCOPE');
 $proof=['schema'=>1,'status'=>'NORMAL_RECEIPTS_VERIFIED','run_id'=>$run,'source_sha'=>$sha,'container_id'=>$id,'actual_native'=>0,'formal_case'=>'T2M_NORMAL','original_G01_G07'=>'NOT RUN','Task2_complete'=>false,'private_cleanup_verified'=>true,'actual_children'=>10];$bytes=json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";$h=fopen('/run/audit/evidence/ms2-migration-host-receipt.json','xb');if(!is_resource($h))throw new RuntimeException('HOST_RECEIPT');try{if(fwrite($h,$bytes)!==strlen($bytes)||!fflush($h))throw new RuntimeException('HOST_WRITE');}finally{fclose($h);}echo "Normal receipt verified\n";
}catch(Throwable $error){fwrite(STDERR,get_class($error)."\n");exit(1);}
PHP
)"
if audit_compose run --rm --no-deps -T audit-bootstrap -r "$m2_verify_php" "$AUDIT_RUN_ID" "$m2_source" "$m2_finite" "$m2_native" > "$m2_output/verify.stdout" 2> "$m2_output/verify.stderr"; then
    m2_verified=1
    printf '{"stage":"verify","actual_native":0}\n' >> "$m2_output/stages.jsonl"
else
    m2_verify_native=$?
    printf '{"stage":"verify","actual_native":%s}\n' "$m2_verify_native" >> "$m2_output/stages.jsonl"
fi
# The finite object has truly exited; now refresh original identities and clean.
m2_stage register-terminal audit_register
m2_stage teardown bash "$audit_script_dir/teardown.sh" "$AUDIT_RUN_ID"
m2_counts=()
for m2_label in "com.docker.compose.project=$AUDIT_PROJECT" "tianli.audit.run=$AUDIT_RUN_ID"; do
    for m2_kind in container network volume; do
        case "$m2_kind" in
            container) m2_ids="$(docker ps -aq --filter "label=$m2_label")" ;;
            network) m2_ids="$(docker network ls -q --filter "label=$m2_label")" ;;
            volume) m2_ids="$(docker volume ls -q --filter "label=$m2_label")" ;;
        esac
        m2_count=0
        while IFS= read -r m2_id; do if [[ -n "$m2_id" ]]; then m2_count=$((m2_count+1)); fi; done <<< "$m2_ids"
        m2_counts+=("$m2_count")
    done
done
printf '{"schema":1,"run_id":"%s","project":{"container":%s,"network":%s,"volume":%s},"run_label":{"container":%s,"network":%s,"volume":%s}}\n' "$AUDIT_RUN_ID" "${m2_counts[@]}" > "$audit_run_path/evidence/ms2-migration-final-six-counts.json"
m2_zero=1
for m2_count in "${m2_counts[@]}"; do [[ "$m2_count" == 0 ]] || m2_zero=0; done
m2_absent=1
[[ ! -e "$audit_repo/crmeb/.env" && ! -L "$audit_repo/crmeb/.env" && ! -e "$audit_repo/crmeb/public/install.lock" && ! -L "$audit_repo/crmeb/public/install.lock" ]] || m2_absent=0
mkdir -m 700 -- "$m2_output/evidence"
for m2_file in "$audit_run_path"/evidence/*.json "$audit_run_path"/evidence/*.jsonl; do [[ -f "$m2_file" ]] || continue; cp -- "$m2_file" "$m2_output/evidence/"; done
m2_result=1 m2_status=FAIL
if [[ "$m2_native" == 0 && "$m2_verified" == 1 && "$m2_zero" == 1 && "$m2_absent" == 1 ]]; then m2_result=0; m2_status=VERIFIED_NORMAL_FOUR_COMMANDS_ONLY; fi
printf '{"schema":1,"status":"%s","run_id":"%s","source_sha":"%s","finite_container_id":"%s","actual_finite_native":%s,"normal_receipts_verified":%s,"all_six_counts_zero":%s,"env_and_lock_absent":%s,"Task2_complete":false,"MS2_complete":false,"historical_acceptance":false,"original_G01_G07":"NOT RUN"}\n' "$m2_status" "$AUDIT_RUN_ID" "$m2_source" "$m2_finite" "$m2_native" "$([[ "$m2_verified" == 1 ]] && printf true || printf false)" "$([[ "$m2_zero" == 1 ]] && printf true || printf false)" "$([[ "$m2_absent" == 1 ]] && printf true || printf false)" > "$m2_output/orchestration.json"
printf 'RUN_ID=%s\nRUN_DIR=%s\nSTATUS=%s\n' "$AUDIT_RUN_ID" "$AUDIT_RUN_DIR" "$m2_status"
exit "$m2_result"
