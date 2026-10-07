## Why

The PHP 8.2 candidate and optimized runtime currently pass their dedicated checks, but the running-stack verifier can still report success when acceptance data has been replaced, a supervised process is restarting, the wrong PHP runtime is deployed, an upload mount is not actually usable, or a WebSocket endpoint returns an arbitrary non-502 error. The archived PHP 8.2 specification already requires snapshot-based data comparison and zero process restarts, so the executable acceptance gate must be brought into agreement with that contract.

An authoritative pre-upgrade snapshot already exists outside the repository. It contains verified canonical schema and data dumps plus a per-file upload SHA256 manifest. This change uses that evidence without committing database contents, uploaded assets, credentials, or environment files.

## What Changes

- Replace hard-coded product, user, and upload counts with a required external snapshot manifest.
- Add a reusable snapshot capture command that records normalized database schema/data digests and each upload file's relative path, size, and SHA256.
- Compare the complete current database and upload tree against the selected snapshot, failing closed on missing, malformed, incomplete, or mismatched evidence.
- Verify the actual running PHP container is PHP 8.2 with the required extensions, enabled OPcache, and disabled JIT.
- Sample Supervisor process identities twice, require stable PIDs and zero recorded restarts, and scan post-workflow logs for PHP fatals, type errors, unexpected deprecations, and repeated worker exits.
- Exercise a real create/read/delete upload operation as `www-data` without leaving a test artifact.
- Check storefront, admin, SPA fallback, and a representative public API route, and require HTTP 101 for both Workerman WebSocket routes.
- Repair the standalone AWS probe command in the Docker README.
- Add deterministic self-tests for snapshot comparison and acceptance-result parsing before changing the production verifier.

## Capabilities

### New Capabilities

- `runtime-acceptance-verification`: Reproducible external snapshots and a fail-closed running-stack acceptance gate for data, PHP, processes, logs, HTTP, WebSocket, and upload I/O.

### Modified Capabilities

- `php-82-runtime`: Make the existing snapshot, runtime, restart, and log-cleanliness requirements executable against the deployed container.
- `optimized-container-runtime`: Strengthen data preservation, managed-process, SPA, WebSocket, and writable-upload verification.

## Impact

- Affected verification files: `help/docker/verify-optimized.ps1`, new PowerShell snapshot/comparison helpers and focused self-tests, and `help/docker/README.md`.
- Affected specifications: `php-82-runtime` and `optimized-container-runtime`, plus the new `runtime-acceptance-verification` capability.
- External evidence: callers must provide an explicit snapshot directory. Snapshot contents remain outside Git.
- Runtime impact: verification performs read-only database exports, hashes the upload tree, samples processes for a bounded interval, writes and removes one probe file, and sends local HTTP/WebSocket requests. It sends no third-party request and performs no schema or business-data mutation.
- Out of scope: Composer security upgrades, live payment/refund/SMS/WeChat/cloud-storage transactions, production TLS/secrets, and deployment to a remote server.
