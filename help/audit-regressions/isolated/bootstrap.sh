#!/usr/bin/env bash
set -euo pipefail
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
audit_script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
audit_repo="$(cd "$audit_script_dir/../../.." && pwd)"
audit_native_path() { if command -v cygpath >/dev/null; then cygpath -m "$1"; else printf '%s\n' "$1"; fi; }
audit_compose() { docker compose --project-name "$AUDIT_PROJECT" --file "$(audit_native_path "$audit_script_dir/compose.yml")" "$@"; }
audit_context() {
    local run_id="$1"
    [[ "$run_id" =~ ^[0-9]{14}[a-f0-9]{6}$ ]] || { echo 'Invalid RUN_ID' >&2; return 2; }
    audit_run_path="${AUDIT_RUN_ROOT:-$HOME/.tianli-audit}/$run_id"
    export AUDIT_RUN_ID="$run_id" AUDIT_PROJECT="tianli-audit-$run_id"
    export AUDIT_RUN_DIR="$(audit_native_path "$audit_run_path")"
    export AUDIT_REPO_DIR="$(audit_native_path "$audit_repo")"
    export AUDIT_SOURCE_SHA="$(git -C "$(audit_native_path "$audit_repo")" rev-parse HEAD)"
}
audit_collect_resources() {
    local id network="${AUDIT_PROJECT}_isolation"
    docker network inspect --format '{"Id":{{json .Id}},"Name":{{json .Name}},"Internal":{{json .Internal}},"Labels":{{json .Labels}}}' "$network" > "$audit_run_path/network.json"
    : > "$audit_run_path/containers.jsonl"
    while IFS= read -r id; do
        [[ -n "$id" ]] || continue
        docker inspect --format '{"id":{{json .Id}},"image_id":{{json .Image}},"mounts":{{json .Mounts}},"labels":{{json .Config.Labels}},"networks":{{json .NetworkSettings.Networks}},"ports":{{json .NetworkSettings.Ports}}}' "$id" >> "$audit_run_path/containers.jsonl"
    done < <(docker ps -aq --filter "label=com.docker.compose.project=$AUDIT_PROJECT")
    docker volume ls -q --filter "label=com.docker.compose.project=$AUDIT_PROJECT" | sort > "$audit_run_path/volumes.txt"
    : > "$audit_run_path/volumes.jsonl"
    while IFS= read -r id; do
        [[ -n "$id" ]] || continue
        docker volume inspect --format '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}}}' "$id" >> "$audit_run_path/volumes.jsonl"
    done < "$audit_run_path/volumes.txt"
}
audit_register() {
    audit_collect_resources
    audit_compose run --rm --no-deps -T audit-bootstrap help/audit-regressions/isolated/lib/harness.php __register
}
audit_bootstrap() {
    local probe="${1:-}" run_id
    for image in tianli/crmeb-php:acceptance ccr.ccs.tencentyun.com/crmebky_php/mysql:v8.0.45 ccr.ccs.tencentyun.com/crmebky_php/redis:v8.6.1; do
        docker image inspect "$image" >/dev/null || { echo "Required local image missing: $image" >&2; return 2; }
    done
    run_id="$(date -u +%Y%m%d%H%M%S)$(openssl rand -hex 3)"
    audit_context "$run_id"
    umask 077
    [[ ! -e "$audit_run_path" ]] || { echo 'Run directory already exists' >&2; return 2; }
    mkdir -m 700 -p "$audit_run_path"
    [[ ! -e "$audit_repo/crmeb/.env" ]] || { echo 'Existing .env is not an isolated configuration; refused' >&2; return 2; }
    audit_compose config --quiet
    local prepare_operation=__prepare
    if [[ "$probe" == '--cleanup-probe' ]]; then prepare_operation=__prepare_probe; fi
    audit_compose run --rm --no-deps -T audit-bootstrap help/audit-regressions/isolated/lib/harness.php "$prepare_operation"
    audit_compose up -d --wait --wait-timeout 90 audit-mysql audit-redis
    audit_register
    if [[ "$probe" != '--cleanup-probe' ]]; then
        audit_compose run --rm --no-deps -T audit-bootstrap help/audit-regressions/isolated/lib/harness.php __seed
        local lock="$audit_repo/crmeb/public/install.lock"
        [[ ! -e "$lock" ]] || { echo 'Existing install.lock will not be overwritten' >&2; return 2; }
        printf '# AUDIT-RUN %s\n' "$run_id" > "$lock"
        audit_compose up -d audit-app
        audit_register
        docker ps -a --filter label=tianli.audit=1 --format '{{.ID}}' | sort > "$audit_run_path/cleanup-before.txt"
        bash "$audit_script_dir/bootstrap.sh" --cleanup-probe > "$audit_run_path/cleanup-probe.log" 2>&1
        local probe_id
        probe_id="$(sed -n 's/^RUN_ID=//p' "$audit_run_path/cleanup-probe.log" | tail -1)"
        [[ "$probe_id" =~ ^[0-9]{14}[a-f0-9]{6}$ ]] || { echo 'Cleanup probe did not initialize' >&2; return 2; }
        bash "$audit_script_dir/teardown.sh" "$probe_id"
        docker ps -a --filter label=tianli.audit=1 --format '{{.ID}}' | sort > "$audit_run_path/cleanup-after.txt"
        cmp "$audit_run_path/cleanup-before.txt" "$audit_run_path/cleanup-after.txt"
        printf '{"run_id":"%s","passed":true}\n' "$run_id" > "$audit_run_path/cleanup-check.json"
    fi
    printf 'RUN_ID=%s\nRUN_DIR=%s\n' "$run_id" "$AUDIT_RUN_DIR"
}
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then audit_bootstrap "${1:-}"; fi
