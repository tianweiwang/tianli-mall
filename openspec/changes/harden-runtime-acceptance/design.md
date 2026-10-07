## Context

`help/docker/verify-optimized.ps1` currently uses the literals 4 products, 2 users, and 174 uploads. It checks Supervisor once, checks only OPcache in the PHP runtime, uses `test -w` for uploads, exercises only the storefront HTTP route, and accepts every WebSocket response except 0 or 502. These checks are useful smoke tests but cannot prove the archived PHP 8.2 acceptance requirements.

The pre-upgrade evidence directory `H:\天俪商城\CRMEB-backups\php82-upgrade-20260730-010226` contains a full SQL dump, canonical data and schema dumps, a 174-file upload archive, a per-file SHA256 CSV, and a written restore verification. The implementation must consume an explicit snapshot path and must not copy those artifacts into the repository.

## Goals / Non-Goals

**Goals:**

- Make a successful running-stack verification meaningful enough to gate the next migration step.
- Detect database, schema, upload-path, upload-size, or upload-content drift from an explicit pre-cutover snapshot.
- Detect wrong PHP images, missing extensions, enabled JIT, unstable supervised processes, relevant runtime diagnostics, broken application routes, invalid WebSocket handshakes, and unusable upload mounts.
- Keep snapshot capture reusable for later dependency and deployment migrations.
- Make comparison logic testable without Docker or a live database.

**Non-Goals:**

- Modify business data, database schema, application code, Docker images, or Composer dependencies.
- Treat frequently changing Redis/cache/runtime state as acceptance data.
- Send any request to an external payment, messaging, WeChat, or storage provider.
- Replace production backup retention and disaster-recovery procedures.

## Decisions

### Use an explicit machine-readable snapshot manifest

The capture command writes a versioned JSON manifest into a caller-selected directory. It records the snapshot creation time, database name, table count, normalized schema SHA256, normalized data SHA256, and a sorted upload array containing relative path, bytes, and SHA256. The command writes through a temporary file and atomically renames it only after every component succeeds. It refuses to overwrite an existing manifest by default.

The verifier requires `-SnapshotDirectory` or `-SnapshotManifest`. Missing or malformed evidence is a failure. It never falls back to hard-coded counts. The current pre-upgrade SQL and upload CSV can be imported into the same manifest format without altering their contents.

### Compare canonical database exports, not physical MySQL files

Physical InnoDB files, binlogs, and container volume metadata change during normal startup and are not portable integrity evidence. The capture and verification commands instead invoke the same pinned `mysqldump` option set for schema-only and data-only exports. The output is written under an OS temporary directory, hashed with SHA256, and removed in `finally` cleanup. Credentials remain inside the MySQL container command and are never written to the manifest or console.

Both schema and data digests must match. Table count remains diagnostic metadata, not the integrity decision. Any export error, warning in place of SQL, or digest mismatch fails verification.

### Compare every uploaded file

Upload entries are normalized to forward-slash relative paths, sorted ordinally, and compared as a set. Missing files, extra files, size changes, hash changes, duplicate manifest paths, path traversal, or paths outside the configured upload root fail verification. Directory timestamps and empty directories are not part of the contract.

### Verify the deployed container rather than only the candidate image

The optimized verifier executes a PHP probe inside `crmeb_php`. It requires PHP `8.2.x`, the documented extension set, enabled OPcache, and disabled JIT/JIT buffer. This prevents a correct candidate image from masking an incorrectly tagged or deployed container.

### Detect process instability over an interval

The verifier records Supervisor status and PIDs for PHP-FPM, queue, timer, and Workerman, waits a short bounded interval, and records them again. Every process must remain `RUNNING`, keep the same PID, and have no unexpected restart evidence. Container restart count must be zero for the acceptance window. Logs collected after the first snapshot are scanned for fatal errors, type errors, unexpected deprecations, process termination, rapid restart, and Workerman child-exit patterns. The one documented static-GIF warning remains the only explicit exclusion.

### Exercise observable routes and real local I/O

The HTTP gate requires 200 for the storefront, admin entry, SPA fallback, and representative public API. Existing warm-storefront latency checks remain. `/notice` and `/msg` must complete a WebSocket upgrade with 101; 4xx/5xx responses fail.

As `www-data`, the verifier creates a uniquely named file below uploads, writes unpredictable local probe content, reads it back, and removes it in guaranteed cleanup. Failure to create, read, compare, or delete fails verification. The probe never enters application metadata or business tables.

### Keep orchestration separate from pure comparison logic

Snapshot parsing, upload-manifest normalization, set comparison, runtime-fact validation, Supervisor snapshot comparison, and log classification live in a small PowerShell module with no Docker side effects. A focused self-test script exercises those functions with temporary fixtures. Docker orchestration remains in the capture and optimized-verification scripts.

## Error Handling

- All missing inputs, malformed JSON, duplicate upload paths, unsupported manifest versions, Docker failures, export failures, and cleanup failures produce a nonzero exit.
- Verification continues through independent checks when safe so the operator receives a complete failure list.
- Snapshot capture stops immediately on incomplete evidence and does not publish a partial manifest.
- Temporary SQL and upload probe files are removed in `finally` blocks.
- Output contains counts, paths, check names, and digests needed for diagnosis, but never credentials or database row contents.

## Testing

1. Write self-tests that fail against the current implementation for hard-coded/missing snapshots, changed database digests, missing/extra/changed uploads, duplicate/traversal paths, unstable PIDs, wrong runtime facts, disallowed logs, non-101 WebSockets, and failed upload cleanup.
2. Add the minimum comparison helpers and watch those tests pass.
3. Integrate the helpers into snapshot capture and `verify-optimized.ps1`.
4. Run the focused self-tests, `openspec validate harden-runtime-acceptance`, PowerShell parser checks, PHP 8.2 candidate verification, and the full optimized-stack verifier against the authoritative pre-upgrade snapshot.
5. Confirm the verifier fails on a temporary copied fixture with one changed byte, then confirm the unchanged authoritative snapshot passes without modifying acceptance data.

## Risks / Trade-offs

- [Full database exports add verification time] -> The acceptance database is small enough for bounded local exports; correctness is more important than a sub-second gate.
- [Normal business writes after the cutover make an immutable snapshot fail] -> This gate is for a quiesced migration/restore window. Later production health checks remain separate and do not compare immutable business data.
- [Canonical dump output can vary across client versions] -> Capture and verification record and require the same MySQL major/client strategy and pinned dump options; version mismatch is reported explicitly.
- [Log history may contain older failures] -> Record a time/log cursor before workflows and scan only new output where the runtime allows it; otherwise use a clearly bounded tail and report that limitation.
- [The external snapshot can be moved] -> Require an explicit path and validate all referenced artifacts by digest rather than relying on a repository-relative location.

## Open Questions

None. The authoritative PHP 7.4 pre-upgrade snapshot is the baseline for this migration, and future migrations will capture a new explicit snapshot before cutover.
