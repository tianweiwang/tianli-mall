# Hardened Runtime Acceptance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace CRMEB's false-positive-prone optimized-runtime checks with snapshot-backed data integrity and strict deployed-runtime acceptance.

**Architecture:** Put deterministic parsing and comparison in one side-effect-free PowerShell module, cover it with a standalone self-test harness, and keep Docker/MySQL/HTTP orchestration in the existing verifier plus one new snapshot command. The verifier captures a temporary current manifest, compares it with an explicit external pre-cutover manifest, then validates the deployed PHP runtime, stable processes, new logs, routes, WebSockets, and real upload I/O.

**Tech Stack:** PowerShell 7, Docker Compose, MySQL 8 `mysqldump`, JSON/CSV, curl, Supervisor, OpenSpec.

---

## File Structure

- Create `help/docker/RuntimeAcceptance.psm1`: pure manifest validation, upload comparison, runtime-fact validation, Supervisor parsing/comparison, log classification, and route-status validation.
- Create `help/docker/tests/RuntimeAcceptance.Tests.ps1`: dependency-free self-test runner for every pure helper.
- Create `help/docker/capture-acceptance-snapshot.ps1`: capture a fresh snapshot or import existing canonical SQL/CSV evidence into versioned JSON.
- Modify `help/docker/verify-optimized.ps1`: orchestrate strict checks and require an explicit snapshot.
- Modify `help/docker/README.md`: document snapshot usage and repair the AWS probe mount.
- Modify `openspec/changes/harden-runtime-acceptance/tasks.md`: track red-green completion and final evidence.

### Task 1: Pure acceptance decisions

**Files:**
- Create: `help/docker/tests/RuntimeAcceptance.Tests.ps1`
- Create: `help/docker/RuntimeAcceptance.psm1`

- [ ] **Step 1: Write the failing self-tests**

Create a dependency-free harness that imports `../RuntimeAcceptance.psm1`, runs named cases, and exits nonzero on any failure. Cover a valid version-1 snapshot plus: missing required properties, duplicate upload paths, `../` traversal, changed schema/data hashes, missing/extra/changed uploads, PHP 7.4, a missing extension, enabled JIT, changed process PID, non-running process, container restart count, fatal/type/deprecation/restart log lines, non-200 HTTP, and non-101 WebSocket status.

The test API must be:

```powershell
$snapshot = Read-AcceptanceSnapshot -LiteralPath $manifestPath
$comparison = Compare-AcceptanceSnapshot -Expected $expected -Actual $actual
$runtime = Test-PhpRuntimeFacts -Facts $facts -RequiredExtensions @('curl', 'redis')
$processes = Compare-SupervisorSnapshots -Before $before -After $after -ContainerRestartCount 0
$violations = Find-RuntimeLogViolation -Lines $lines
$routes = Test-RouteStatuses -Statuses @{ storefront = 200; notice = 101 } -Expected @{ storefront = 200; notice = 101 }
```

- [ ] **Step 2: Run the self-tests and verify RED**

Run:

```powershell
pwsh -NoProfile -File help/docker/tests/RuntimeAcceptance.Tests.ps1
```

Expected: nonzero exit because `RuntimeAcceptance.psm1` or its exported functions do not exist.

- [ ] **Step 3: Implement the minimum pure module**

Export exactly these functions:

```powershell
Export-ModuleMember -Function @(
    'Read-AcceptanceSnapshot',
    'Compare-AcceptanceSnapshot',
    'Test-PhpRuntimeFacts',
    'ConvertFrom-SupervisorStatus',
    'Compare-SupervisorSnapshots',
    'Find-RuntimeLogViolation',
    'Test-RouteStatuses'
)
```

All decision functions return `[pscustomobject]@{ Passed = [bool]; Differences = [string[]] }`, except the parser functions. Normalize upload paths to `/`, compare them with ordinal case-sensitive keys, reject rooted/traversing/duplicate paths, uppercase SHA256 values, and never read Docker or environment state from the module.

- [ ] **Step 4: Run the self-tests and verify GREEN**

Run the command from Step 2. Expected: every named case prints `[PASS]` and the summary reports zero failures.

- [ ] **Step 5: Commit the pure module and tests**

```powershell
git add -- help/docker/RuntimeAcceptance.psm1 help/docker/tests/RuntimeAcceptance.Tests.ps1
git commit -m "test: define strict runtime acceptance decisions"
```

### Task 2: Snapshot capture and import

**Files:**
- Modify: `help/docker/tests/RuntimeAcceptance.Tests.ps1`
- Modify: `help/docker/RuntimeAcceptance.psm1`
- Create: `help/docker/capture-acceptance-snapshot.ps1`

- [ ] **Step 1: Add failing manifest-construction tests**

Add tests for sorted upload entries, exact byte totals, SQL SHA256 calculation, CSV import, unsupported CSV columns, and atomic output refusal when `acceptance-snapshot.json` already exists. The intended manifest shape is:

```json
{
  "formatVersion": 1,
  "createdAtUtc": "2026-07-30T00:00:00.0000000Z",
  "database": {
    "name": "crmeb",
    "tableCount": 157,
    "schemaSha256": "64_HEX_CHARACTERS",
    "dataSha256": "64_HEX_CHARACTERS",
    "dumpClient": "mysqldump 8.0"
  },
  "uploads": {
    "fileCount": 174,
    "totalBytes": 21664281,
    "files": [
      { "relativePath": "attach/example.png", "bytes": 10176, "sha256": "64_HEX_CHARACTERS" }
    ]
  }
}
```

- [ ] **Step 2: Run tests and verify RED**

Expected: failures for missing `New-AcceptanceSnapshotManifest`, `Import-UploadHashCsv`, and `Write-AcceptanceSnapshotManifest`.

- [ ] **Step 3: Implement pure manifest construction**

Add and export:

```powershell
Import-UploadHashCsv
New-UploadSnapshotEntry
New-AcceptanceSnapshotManifest
Write-AcceptanceSnapshotManifest
```

`Write-AcceptanceSnapshotManifest` writes UTF-8 JSON to a sibling temporary file, closes it, then uses `Move-Item` to publish `acceptance-snapshot.json`. It rejects pre-existing output and deletes partial temporary files in `finally`.

- [ ] **Step 4: Verify GREEN before orchestration**

Run the focused self-tests and require zero failures.

- [ ] **Step 5: Add snapshot orchestration**

`capture-acceptance-snapshot.ps1` accepts `-Mode Capture|Import`, mandatory `-OutputDirectory`, and explicit input paths for Import. Capture mode exports canonical schema/data inside `crmeb_mysql`, copies the files to the external output directory, hashes them, inventories `crmeb/public/uploads`, and writes the JSON last. Import mode hashes existing canonical schema/data SQL and imports the existing upload SHA256 CSV without changing those artifacts.

The Docker-side export command uses the container's own password environment and never interpolates a secret into PowerShell output:

```sh
mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --skip-comments --compact --skip-dump-date --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 --no-data crmeb
```

Data export additionally uses `--no-create-info --hex-blob --order-by-primary` and retains MySQL's default extended INSERT format so its bytes match the verified pre-upgrade canonical dump.

- [ ] **Step 6: Import the authoritative baseline**

Run Import mode against:

```text
H:\天俪商城\CRMEB-backups\php82-upgrade-20260730-010226\crmeb-schema-pre-php82.sql
H:\天俪商城\CRMEB-backups\php82-upgrade-20260730-010226\crmeb-data-pre-php82.sql
H:\天俪商城\CRMEB-backups\php82-upgrade-20260730-010226\uploads-pre-php82.sha256.csv
```

Write `acceptance-snapshot.json` into that same external directory and verify it records 157 tables, 174 files, and 21,664,281 upload bytes. Do not stage the external manifest.

- [ ] **Step 7: Commit snapshot tooling**

```powershell
git add -- help/docker/RuntimeAcceptance.psm1 help/docker/tests/RuntimeAcceptance.Tests.ps1 help/docker/capture-acceptance-snapshot.ps1
git commit -m "feat: add external acceptance snapshots"
```

### Task 3: Strict running-stack verifier

**Files:**
- Modify: `help/docker/tests/RuntimeAcceptance.Tests.ps1`
- Modify: `help/docker/verify-optimized.ps1`

- [ ] **Step 1: Add failing contract tests**

Add source-contract assertions that the verifier requires `-SnapshotManifest`, contains no `ExpectedProducts`, `ExpectedUsers`, or `ExpectedUploads`, requires `notice=101` and `msg=101`, calls deployed PHP fact validation, takes two Supervisor snapshots, scans logs since the acceptance start time, and uses a create/read/delete upload probe.

- [ ] **Step 2: Run tests and verify RED**

Expected: contract failures against the current verifier's hard-coded values and weak WebSocket/upload/process checks.

- [ ] **Step 3: Require and compare the snapshot**

Replace the three hard-coded expected counts with:

```powershell
[Parameter(Mandatory)]
[string]$SnapshotManifest
```

Capture a current manifest under a new temporary directory, compare it with `Read-AcceptanceSnapshot`, add one check per database/upload difference, and remove the temporary directory in `finally`.

- [ ] **Step 4: Verify deployed PHP facts**

Run one JSON-emitting `php -r` command inside `crmeb_php` and feed it to `Test-PhpRuntimeFacts`. Required extensions are `bcmath,curl,fileinfo,gd,iconv,mbstring,mysqli,pdo_mysql,pcntl,redis,simplexml,sockets,sodium,xml,zip`; OPcache must be loaded/enabled and both JIT setting and buffer must be disabled/zero.

- [ ] **Step 5: Verify stable processes and new logs**

Record UTC start time, parse `supervisorctl status`, wait 5 seconds, parse again, inspect Docker `RestartCount`, and add failures from `Compare-SupervisorSnapshots`. After HTTP/upload workflows, collect `docker logs --since <UTC>` plus runtime log files readable inside the PHP container and pass their lines through `Find-RuntimeLogViolation`.

- [ ] **Step 6: Verify real upload I/O and strict routes**

As `www-data`, use `mktemp` below `/var/www/public/uploads`, write a UUID value, compare the read value, and delete the file in a shell trap. Request `/`, `/admin/`, `/pages/index/index`, and `/api/index` with expected 200; require exactly 101 from `/notice` and `/msg`.

- [ ] **Step 7: Run tests and verify GREEN**

Run the focused self-tests. Expected: all decision and source-contract cases pass.

- [ ] **Step 8: Commit verifier integration**

```powershell
git add -- help/docker/verify-optimized.ps1 help/docker/tests/RuntimeAcceptance.Tests.ps1
git commit -m "fix: make runtime acceptance fail closed"
```

### Task 4: Documentation and full verification

**Files:**
- Modify: `help/docker/README.md`
- Modify: `openspec/changes/harden-runtime-acceptance/tasks.md`
- Modify: `docs/superpowers/plans/2026-07-30-harden-runtime-acceptance.md`

- [ ] **Step 1: Document the exact commands**

Document Import/Capture modes, the required external manifest, the quiesced migration-window limitation, and the full verifier command. Repair the AWS command by adding:

```powershell
-v ${PWD}\help\docker\probes:/probes:ro
```

- [ ] **Step 2: Run parser and focused tests**

```powershell
pwsh -NoProfile -File help/docker/tests/RuntimeAcceptance.Tests.ps1
pwsh -NoProfile -Command "[void][ScriptBlock]::Create((Get-Content -Raw help/docker/capture-acceptance-snapshot.ps1)); [void][ScriptBlock]::Create((Get-Content -Raw help/docker/verify-optimized.ps1))"
```

Expected: zero failures and exit 0.

- [ ] **Step 3: Run specification validation**

```powershell
openspec validate harden-runtime-acceptance
openspec validate --all
```

Expected: the active change and both published specs validate with zero failures.

- [ ] **Step 4: Run candidate verification**

```powershell
pwsh -NoProfile -File help/docker/verify-php82.ps1 -Mode Candidate -Image tianli/crmeb-php:acceptance
```

Expected: `CANDIDATE_STATUS=GREEN`.

- [ ] **Step 5: Run full optimized-stack verification**

```powershell
pwsh -NoProfile -File help/docker/verify-optimized.ps1 -SnapshotManifest "H:\天俪商城\CRMEB-backups\php82-upgrade-20260730-010226\acceptance-snapshot.json"
```

Expected: strict runtime, process, log, route, WebSocket, upload-I/O, database, and upload-integrity checks all pass.

- [ ] **Step 6: Prove negative fixtures fail**

Copy the external JSON and one upload fixture into an OS temporary directory, alter one digest, run pure comparison, and require a nonzero decision. Remove the temporary directory afterward without touching the authoritative backup.

- [ ] **Step 7: Complete OpenSpec task evidence and review scope**

Mark only verified tasks complete. Run `git diff --check`, inspect the scoped diff, and confirm `.env`, `.constant`, `.codex/`, database dumps, uploads, and external snapshot contents are absent from Git status.

- [ ] **Step 8: Commit verified documentation and task state**

```powershell
git add -- help/docker/README.md openspec/changes/harden-runtime-acceptance/tasks.md docs/superpowers/plans/2026-07-30-harden-runtime-acceptance.md
git commit -m "docs: record hardened acceptance workflow"
```
