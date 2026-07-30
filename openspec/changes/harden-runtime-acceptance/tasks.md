## 1. Red verification baseline

- [x] 1.1 Add focused PowerShell self-tests for snapshot parsing and comparison, runtime facts, process stability, log classification, route expectations, and upload cleanup.
- [x] 1.2 Run the self-tests against the current code and record failures caused by the missing behavior.

## 2. Snapshot evidence

- [x] 2.1 Add pure helpers for versioned manifests, database digests, and normalized upload entries.
- [x] 2.2 Add a capture/import command that writes a complete manifest atomically to an explicit external directory.
- [x] 2.3 Import the verified PHP 7.4 pre-upgrade SQL and upload manifest into the machine-readable baseline without modifying the source artifacts.
- [x] 2.4 Prove one-byte database or upload fixture changes fail comparison and unchanged fixtures pass.

## 3. Running-stack verification

- [x] 3.1 Require an explicit snapshot and remove hard-coded product/user/upload expectations.
- [x] 3.2 Verify PHP 8.2, required extensions, OPcache, and disabled JIT inside the running PHP container.
- [x] 3.3 Compare two Supervisor/PID snapshots, container restart state, and post-workflow logs.
- [x] 3.4 Perform create/read/delete upload I/O as `www-data` with guaranteed cleanup.
- [x] 3.5 Require 200 for storefront, admin, SPA, and public API routes, and exactly 101 for both WebSocket routes.

## 4. Documentation and verification

- [x] 4.1 Document snapshot capture/import, strict optimized verification, expected runtime, and migration-window limitations.
- [x] 4.2 Repair the standalone AWS probe command by mounting the probes directory read-only.
- [x] 4.3 Run focused self-tests and PowerShell parser checks.
- [x] 4.4 Run `openspec validate harden-runtime-acceptance`.
- [x] 4.5 Run PHP 8.2 candidate verification and the full optimized-stack verifier against the authoritative snapshot.
- [x] 4.6 Review the final diff and confirm no database contents, uploads, credentials, `.env`, `.constant`, or `.codex/` files entered the change.
