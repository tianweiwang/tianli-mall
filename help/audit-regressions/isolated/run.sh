#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/bootstrap.sh"
[[ $# -ge 2 ]] || { echo 'Usage: run.sh RUN_ID repository-relative-script [arguments...]' >&2; exit 2; }
audit_context "$1"; shift
[[ -f "$audit_run_path/manifest.json" ]] || { echo 'Unknown run' >&2; exit 2; }
[[ "$1" == help/audit-regressions/* && "$1" != *..* ]] || { echo 'Script outside approved regression directory' >&2; exit 2; }
export AUDIT_PHASE="${AUDIT_PHASE:-after}"
audit_register
audit_compose run --rm --no-deps -T audit-runner "$@" &
runner_pid=$!
redis_stopped=false
trap 'if [[ "$redis_stopped" == true ]]; then audit_compose start audit-redis >/dev/null; fi' EXIT
while kill -0 "$runner_pid" 2>/dev/null; do
    for action in stop start; do
        request="$audit_run_path/$action-redis.request"
        acknowledgement="$audit_run_path/$action-redis.done"
        if [[ -f "$request" && ! -f "$acknowledgement" ]]; then
            [[ "$(cat "$request")" == "$AUDIT_RUN_ID" ]] || { echo 'Invalid service control identity' >&2; exit 2; }
            audit_compose "$action" audit-redis >/dev/null
            if [[ "$action" == stop ]]; then redis_stopped=true; else
                redis_stopped=false
                audit_compose run --rm --no-deps -T audit-bootstrap help/audit-regressions/isolated/lib/harness.php __check_redis
            fi
            printf '%s\n' "$AUDIT_RUN_ID" > "$acknowledgement"
        fi
    done
    sleep 0.2
done
result=0
wait "$runner_pid" || result=$?
audit_register
exit "$result"
