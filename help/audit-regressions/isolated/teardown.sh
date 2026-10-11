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
printf 'Cleaned only %s; evidence retained in %s\n' "$AUDIT_PROJECT" "$AUDIT_RUN_DIR"
