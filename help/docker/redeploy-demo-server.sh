#!/bin/sh
set -eu

cd "$(dirname "$0")"

: "${MARKETPLACE_CHECKER_IMAGE:?Set an approved fixed sha256 checker image ID}"

# Check only by default. An explicitly requested --apply uses the same version gate.
exec python3 ./deploy-marketplace.py \
    --env-file .env.runtime \
    --compose-file docker-compose.demo-server.yml \
    --checker-image "$MARKETPLACE_CHECKER_IMAGE" "$@"
