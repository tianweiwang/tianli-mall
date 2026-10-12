# MS0a isolated regression environment

2026-10-11 user decision: skip further security reproduction, fault injection,
and the prepared HTTP concurrency/disconnect experiments. `run.sh` rejects the
existing combined suites before reading the RUN or invoking Docker (exit 2,
not PASS). The commands below describe the prior workflow and are currently
disabled where they select those suites. Keep past evidence; use separate normal
business checks for further functional development. Do not resume the skipped
checks through another entry point. See `docs/multi-merchant/05-plan-ms0b.md`.

2026-10-12 ordinary development also skips the legacy `CREATE DATABASE`
privilege-denial probe. Start new ordinary runs with
`AUDIT_GUARD_MODE=read-only bash help/audit-regressions/isolated/bootstrap.sh`.
The RUN manifest records the mode; all original identity, internal endpoint,
database/Redis sentinel and exact DML-grant checks still run. Ordinary guard
entry uses `lib/guard.php --read-only`; Task6 requires that manifest mode before
its first guard call. A resident PHP supervisor needs a normal reload to load
the changed guard source. Historical bootstrap/baseline probe activity is retained
as historical execution, while later ordinary evidence records the probe NOT RUN.
Missing mode preserves the disabled legacy workflow's active-probe behavior;
unknown values are rejected. This mode does not enable any skipped suite.

This environment implements the approved Windows ISO-FULL adaptation in
`docs/multi-merchant/03-test-environment.md` §2.1. Use Git Bash on Windows, or Bash
with Docker Compose. PHP, the application, MySQL and Redis all run in dedicated
containers on a new **internal** network, without published ports or a Docker
socket. Existing database/Redis instances and credentials are never used.

Required **local** images (automatic pulling is disabled):

- `tianli/crmeb-php:acceptance`: PHP 8.2, PDO MySQL, Redis, curl, pcntl and `setsid`.
- `ccr.ccs.tencentyun.com/crmebky_php/mysql:v8.0.45`
- `ccr.ccs.tencentyun.com/crmebky_php/redis:v8.6.1`

From the test worktree:

```bash
bash help/audit-regressions/isolated/bootstrap.sh
# Copy the emitted RUN_ID (never a password).
bash help/audit-regressions/isolated/run.sh <RUN_ID> help/audit-regressions/isolated/selftest.php
bash help/audit-regressions/isolated/run.sh <RUN_ID> help/audit-regressions/security.php
AUDIT_PHASE=after bash help/audit-regressions/isolated/run.sh <RUN_ID> help/audit-regressions/isolated/ms0a-auth.php
bash help/audit-regressions/isolated/teardown.sh <RUN_ID>
```

The bootstrap creates random credentials in this project's private Docker
volumes. Bootstrap/root and migrator credentials are absent from the application
and test containers. Application/test accounts receive only SELECT, INSERT,
UPDATE and DELETE on `audit_auth`. Installation SQL is loaded from the tracked
`crmeb/public/install/crmeb.sql`, with its normalized SHA-256 recorded. Fixtures
use synthetic accounts with intentionally colliding numeric IDs across apps.
No business data is copied; no authentication schema is added.

Tracked dependencies are copied into a dedicated vendor volume and Composer
regenerates optimized autoload files from the current composer.json, offline
with plugins and scripts disabled. This activates the project's existing PHP
8.2 compatibility classmaps, as the repository Dockerfile does. Source and lock
files are unchanged; the application and runner mount that volume read-only.

For MS0b Task 8 normal file production, application and runner share this RUN's
dedicated `backup-output` volume at `/workspace/crmeb/backup`. The source and
vendor mounts remain read-only; no existing host backup is exposed or copied.
The volume carries the same RUN labels and is recorded and removed by the
existing manifest-based cleanup. Normal checks record the actual produced files
and their hashes. This does not enable any skipped security or fault suite.

After seed import and guard verification, bootstrap uses its dedicated migrator
to run the existing file checksum initialization and verifies the generated
checksums before recording the actual version marker. This is the approved
installation preparation for login/info; the application account remains DML-only.

The host run directory is `${AUDIT_RUN_ROOT:-$HOME/.tianli-audit}/<RUN_ID>`.
It contains the manifest and redacted evidence; credential files remain in
Docker volumes. Do not commit the run directory or copy secret-volume contents
into evidence. `crmeb/.env` must be absent; an existing file is never overwritten.
Only a new `install.lock` tagged with the run ID is created during bootstrap.
For full MS0a tests or `--only` including A04, run.sh verifies the guard and
temporarily installs `app/adminapi/route/crud/audit-ms0a.php` on the host. Its
first line contains the exact run marker and it targets the existing readonly
product-rule list. Source remains read-only inside containers. The EXIT trap
removes only its own probe; teardown provides the same recovery cleanup. A
directory created for that run is removed only when empty and its marker matches.

Historical MS0a baseline runs require a separate worktree with business source identical to
`9685731dcd116784d38e6bbdb902cec3c0c84975` and no changes under crmeb. Copy only
the committed regression harness from the frozen verification source into that
worktree, bootstrap a new dedicated run there, and use `AUDIT_PHASE=baseline`.
The host wrapper rejects historical MS0a baseline labelling on changed or repaired business
source. Never relabel an after run as historical baseline just by changing phase.

MS0b pre-implementation tests use baseline with the actual development-parent SHA.
They are not the historical AUDIT_BASE_SHA: keep both provenance and failures.

Every test/app startup checks the run identity, registered container addresses,
internal network, database and Redis sentinels, and restricted database grants.
An unknown target exits 2. Bootstrap also creates and tears down a disposable
second project and compares the other audit container IDs before/after.
Teardown checks current IDs and volumes against the manifest, then removes only
that compose project. Run directories/evidence are retained, including on failure.

Exit codes: 0 = all executed cases pass; 1 = test failure; 2 = invalid environment
or invocation. Each subcase produces a JSONL result with an explicit test layer.
UNIT-STUB counts do not establish HTTP/database/Redis behavior. WORKER, BROWSER
and EXTERNAL coverage is not implied by this environment.

On startup failure, inspect only this run's container status and sanitized
runtime log. Never solve an environment failure by using a shared instance,
loosening the guard, changing assertions or deleting another project's data.
For MS0b harness validation, set AUDIT_MILESTONE=MS0b before calling run.sh with
selftest.php. The original six checks remain; six extra integration checks cover
no egress, no database/cache host bindings, real EVAL before/after/disconnect
faults, and manifest-owned Redis pause/unpause/stop/start. Every control action
rechecks actual container/network/volume identities. Recovery is authorized
without requiring an unavailable Redis, then confirms its sentinel and full
startup guard. EXIT restores any faulted service and confirms its sentinel.
Only exact control files belonging to this RUN are removed before a new run.

FaultyRedis is a test-only subclass delegating to a real Redis connection. A plan
entry has command, occurrence (one-based), when (before/after), and action
(throw/disconnect). Installed phpredis returns true for a default EVAL status OK;
tests accept strict true or literal OK and also check the actual stored value.
No token-return or single-use-session guarantee is inferred from adapter tests:
those require AuthSessionStore/AuthTokenV2 and their integration suites.
