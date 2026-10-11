#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/bootstrap.sh"
[[ $# -eq 1 ]] || { echo 'Usage: teardown.sh RUN_ID' >&2; exit 2; }
audit_context "$1"
[[ -f "$audit_run_path/manifest.json" ]] || { echo 'Unknown run; no cleanup attempted' >&2; exit 2; }
audit_collect_resources
audit_compose run --rm --no-deps -T audit-bootstrap help/audit-regressions/isolated/lib/harness.php __authorize_cleanup
audit_compose down --volumes --remove-orphans
lock="$audit_repo/crmeb/public/install.lock"
if [[ -f "$lock" && "$(head -n 1 "$lock")" == "# AUDIT-RUN $AUDIT_RUN_ID" ]]; then
    rm -- "$lock"
fi
audit_repo_real="$(cd "$audit_repo" && pwd -P)"
crud_probe_dir="$audit_repo_real/crmeb/app/adminapi/route/crud"
crud_probe="$crud_probe_dir/audit-ms0a.php"
crud_dir_marker="$audit_run_path/crud-probe-directory-created"
if [[ -e "$crud_probe" || -L "$crud_probe" ]]; then
    # The fixed absolute target must remain the same after symlink resolution.
    # Never remove another run's probe, a symlink, or any unrecognized content.
    if [[ -f "$crud_probe" && ! -L "$crud_probe" && "$(realpath -- "$crud_probe")" == "$crud_probe" && "$(head -n 1 "$crud_probe")" == "<?php // AUDIT-RUN $AUDIT_RUN_ID" ]]; then
        rm -- "$crud_probe"
        if [[ -f "$crud_dir_marker" && ! -L "$crud_dir_marker" && "$(cat "$crud_dir_marker")" == "$AUDIT_RUN_ID" && ! -L "$crud_probe_dir" && "$(realpath -- "$crud_probe_dir")" == "$crud_probe_dir" ]]; then
            # rmdir removes only an empty directory created for this run.
            rmdir -- "$crud_probe_dir" 2>/dev/null || true
            rm -- "$crud_dir_marker"
        fi
    else
        printf 'Preserved unrecognized or other-run CRUD probe; no source cleanup attempted\n'
    fi
fi
printf 'Cleaned only %s; evidence retained in %s\n' "$AUDIT_PROJECT" "$AUDIT_RUN_DIR"
