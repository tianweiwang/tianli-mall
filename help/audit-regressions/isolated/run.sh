#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/bootstrap.sh"
[[ $# -ge 2 ]] || { echo 'Usage: run.sh RUN_ID repository-relative-script [arguments...]' >&2; exit 2; }
audit_context "$1"; shift
[[ -f "$audit_run_path/manifest.json" ]] || { echo 'Unknown run' >&2; exit 2; }
[[ "$1" == help/audit-regressions/* && "$1" != *..* ]] || { echo 'Script outside approved regression directory' >&2; exit 2; }
export AUDIT_PHASE="${AUDIT_PHASE:-after}"
if [[ "$AUDIT_PHASE" == baseline && "$1" == help/audit-regressions/isolated/ms0a-auth.php ]]; then
    baseline_sha=9685731dcd116784d38e6bbdb902cec3c0c84975
    baseline_git_repo="$(audit_native_path "$audit_repo")"
    baseline_tree="$(git -c "safe.directory=$baseline_git_repo" -C "$baseline_git_repo" rev-parse "$baseline_sha:crmeb")"
    current_tree="$(git -c "safe.directory=$baseline_git_repo" -C "$baseline_git_repo" rev-parse HEAD:crmeb)"
    if [[ "$baseline_tree" != "$current_tree" || -n "$(git -c "safe.directory=$baseline_git_repo" -C "$baseline_git_repo" status --porcelain --untracked-files=normal -- crmeb)" ]]; then
        echo 'Baseline requires unchanged historical business source; refused to mislabel results' >&2; exit 2
    fi
fi
audit_register
for action in pause unpause stop start; do
    for suffix in request done; do
        control="$audit_run_path/$action-redis.$suffix"
        if [[ -f "$control" ]]; then
            [[ "$(cat "$control")" == "$AUDIT_RUN_ID" ]] || { echo 'Foreign service control file; refused' >&2; exit 2; }
            rm -- "$control"
        fi
    done
done
crud_probe="$audit_repo/crmeb/app/adminapi/route/crud/audit-ms0a.php"
crud_directory_marker="$audit_run_path/crud-probe-directory-created"
probe_installed=false
redis_stopped=false
redis_paused=false
audit_redis_service_control() {
    local action="$1"
    audit_collect_resources
    audit_compose run --rm --no-deps -T audit-bootstrap help/audit-regressions/isolated/lib/harness.php __authorize_redis_control
    audit_compose "$action" audit-redis >/dev/null
    if [[ "$action" == start || "$action" == unpause ]]; then
        audit_compose run --rm --no-deps -T audit-bootstrap help/audit-regressions/isolated/lib/harness.php __check_redis
    fi
}
audit_runner_cleanup() {
    if [[ "$redis_paused" == true ]]; then audit_redis_service_control unpause; fi
    if [[ "$redis_stopped" == true ]]; then audit_redis_service_control start; fi
    if [[ "$probe_installed" == true && -f "$crud_probe" && "$(head -n 1 "$crud_probe")" == "<?php // AUDIT-RUN $AUDIT_RUN_ID" ]]; then
        [[ "$(realpath -m "$crud_probe")" == "$audit_repo/crmeb/app/adminapi/route/crud/audit-ms0a.php" ]] || return 2
        rm -- "$crud_probe"
    fi
    if [[ -f "$crud_directory_marker" && "$(cat "$crud_directory_marker")" == "$AUDIT_RUN_ID" ]]; then
        [[ "$(realpath -m "$(dirname "$crud_probe")")" == "$audit_repo/crmeb/app/adminapi/route/crud" ]] || return 2
        rmdir -- "$(dirname "$crud_probe")" 2>/dev/null || true
        rm -- "$crud_directory_marker"
    fi
}
trap audit_runner_cleanup EXIT
if [[ "$1" == help/audit-regressions/isolated/ms0a-auth.php ]]; then
    needs_crud_probe=true
    for argument in "$@"; do
        if [[ "$argument" == --only=* ]]; then
            [[ ",${argument#--only=}," == *,A04,* ]] || needs_crud_probe=false
        fi
    done
    if [[ "$needs_crud_probe" == true ]]; then
        audit_compose run --rm --no-deps -T audit-runner help/audit-regressions/isolated/lib/guard.php
        [[ "$(realpath -m "$crud_probe")" == "$audit_repo/crmeb/app/adminapi/route/crud/audit-ms0a.php" ]] || { echo 'CRUD probe target rejected' >&2; exit 2; }
        if [[ -e "$crud_probe" && "$(head -n 1 "$crud_probe")" != "<?php // AUDIT-RUN $AUDIT_RUN_ID" ]]; then
            echo 'Existing CRUD probe belongs to another run; refused' >&2; exit 2
        fi
        if [[ ! -d "$(dirname "$crud_probe")" ]]; then
            mkdir -- "$(dirname "$crud_probe")"
            printf '%s\n' "$AUDIT_RUN_ID" > "$crud_directory_marker"
        fi
        printf '%s\n' "<?php // AUDIT-RUN $AUDIT_RUN_ID" '\think\facade\Route::get("crud/audit-ms0a", "v1.product.StoreProductRule/index");' > "$crud_probe"
        probe_installed=true
    fi
fi
audit_compose run --rm --no-deps -T audit-runner "$@" &
runner_pid=$!
while kill -0 "$runner_pid" 2>/dev/null; do
    for action in pause unpause stop start; do
        request="$audit_run_path/$action-redis.request"
        acknowledgement="$audit_run_path/$action-redis.done"
        if [[ -f "$request" && ! -f "$acknowledgement" ]]; then
            [[ "$(cat "$request")" == "$AUDIT_RUN_ID" ]] || { echo 'Invalid service control identity' >&2; exit 2; }
            audit_redis_service_control "$action"
            if [[ "$action" == pause ]]; then redis_paused=true; fi
            if [[ "$action" == unpause ]]; then redis_paused=false; fi
            if [[ "$action" == stop ]]; then redis_stopped=true; fi
            if [[ "$action" == start ]]; then redis_stopped=false; fi
            printf '%s\n' "$AUDIT_RUN_ID" > "$acknowledgement"
        fi
    done
    sleep 0.2
done
result=0
wait "$runner_pid" || result=$?
audit_register
exit "$result"
