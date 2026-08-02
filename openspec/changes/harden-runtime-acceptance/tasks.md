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

## 5. Storefront bootstrap regression

- [ ] 5.1 Add failing tests for invalid business JSON payloads, ThinkORM integer SQL rendering, the reachable Think collection, and the default integer coupon-category filter, then reproduce the current blank-page, empty-product, and managed-worker failures.
- [ ] 5.2 Add a project-owned MySQL connector that follows upstream 2.0 SQL rendering and select it without changing the Composer lock or `vendor/`.
- [ ] 5.3 Make `sys_config()` return its default safely for missing values while preserving scalar and array behavior.
- [ ] 5.4 Require copyright, theme, home-layout, and language bootstrap APIs to return valid JSON with business `status=200`.
- [ ] 5.5 Cover `think\Collection` in the strict PHP 8.2 probe and load a project-owned return-type-compatible implementation without changing `composer.lock` or `vendor/`.
- [ ] 5.6 Keep the default integer coupon-category value from activating an empty product list/count subquery on PHP 8.2.
- [ ] 5.7 Enforce LF-only Linux entrypoints, rebuild the PHP image, and verify the bootstrap and product APIs, Candidate suite, strict snapshot acceptance, OpenSpec, and protected-file boundaries.
