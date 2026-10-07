# PHP 8.2 Compatibility Migration Design

**Status:** Superseded on 2026-07-29 by `openspec/changes/upgrade-php-82-runtime/design.md`. Retained as the original planning record; do not implement from this document.

Its dependency and vendor findings were measured directly against PHP 8.2.32 and three did not hold: the targeted dependency update closes without a major EasyWechat upgrade, the minimum compatible lock delta is 10 updates plus 3 additions, and vendor parse failures are 25 across 6 packages rather than one in Doctrine Cache. Composer's non-minimal solver can also reproduce a broader 23-update, 3-addition, 2-removal delta, but that refresh is not required for PHP 8.2 compatibility. The earlier baseline was taken with `find | xargs php -l`, which aborts the whole batch on the first fatal parse error and skipped the rest of the tree. See the superseding design for the corrected evidence.

The implementation will be tracked by the OpenSpec change `upgrade-php-82-runtime`.

## Goal

Move the optimized CRMEB acceptance runtime from PHP 7.4 to the PHP 8.2 release line while preserving the current application behavior, database records, uploads, and rollback path.

This is a compatibility migration, not the final public-production security release. Major upgrades of JWT, Guzzle, EasyWechat, payment SDKs, and cloud-storage SDKs remain a separate follow-up change.

## Current Evidence

- The current optimized PHP image runs PHP 7.4.33 on Debian 11.
- The upstream CRMEB `master` branch is the same `cb355bab` baseline as this repository and has no newer PHP 8 branch to merge.
- `crmeb/public/index.php` explicitly rejects PHP 8 and describes PHP 7.1 through 7.4 as the supported range.
- `composer.json` permits PHP 8 in theory, but disables Composer platform checks.
- `composer prohibits php 8.2 --locked` reports ten locked packages that reject PHP 8.2.
- PHP 8.2 linting parses 1,173 application PHP files, but reports 32 optional-before-required parameter deprecations.
- The current vendor tree has at least one PHP 8.2 parse failure in Doctrine Cache.
- The application has no maintained first-party automated test suite. The file named `app/adminapi/controller/Test.php` is an application controller, not a test suite.
- The locked dependency audit reports 62 security advisories across 12 packages and one abandoned EasyWechat package. These findings predate this migration and remain a release blocker after compatibility work.

## Scope

### Included

- Build a PHP 8.2 FPM runtime from an official PHP image.
- Install and verify the PHP extensions used by the current PHP 7.4 runtime.
- Restrict the Composer platform requirement to the PHP 8.2 release line and enable platform checks.
- Update only the locked packages required for PHP 8.2 compatibility and their necessary transitive dependencies.
- Allow the web entrypoint to run on PHP 8.2 and reject unvalidated PHP release lines.
- Resolve the 32 application parameter declaration deprecations without changing positional call semantics.
- Add repeatable PHP 8.2 build, lint, dependency, process, HTTP, WebSocket, JWT, spreadsheet, upload, and data-preservation checks.
- Preserve a tested PHP 7.4 image checkpoint for immediate rollback.

### Excluded

- Major-version migration of `firebase/php-jwt`, Guzzle, EasyWechat, COS, payment, SMS, or cloud-storage SDKs.
- Live payment, refund, SMS, WeChat, or cloud-storage transactions.
- Database schema changes or data migrations.
- TLS, public-domain routing, production secrets, backup retention policy, and disaster-recovery automation.
- A claim that the resulting stack is ready for public production use.

## Approaches Considered

### 1. Surgical PHP 8.2 compatibility update

Update only the ten blockers and three required transitive packages, then change the runtime and application declarations. Keep ThinkPHP, Think Queue, EasyWechat, JWT, and Guzzle on their current major versions.

This is the selected approach because it gives the smallest reviewable compatibility change and keeps the existing CRMEB integration APIs stable.

### 2. Refresh every dependency allowed by current constraints

A dry run updates many unrelated packages and changes several transitive selections. It expands the regression surface without resolving the abandoned SDK and security architecture. This approach is rejected for this change.

### 3. Combine compatibility and security modernization

Moving JWT, Guzzle, PSR-7, EasyWechat, and COS to maintained major versions expands dependency resolution to dozens of packages and affects approximately 39 WeChat integration files. With the repository's `minimum-stability: dev`, naive resolution also selects development branches.

This is necessary before public launch, but it must be split into separately testable JWT, WeChat/HTTP/storage, and framework dependency changes.

## Runtime Architecture

The Nginx, MySQL, Redis, Supervisor, database, runtime-volume, and upload boundaries remain unchanged. Only the PHP application image and compatibility validation change.

The PHP image will derive from the official `php:8.2-fpm-bookworm` release line. The build must explicitly install the current runtime capabilities, including BCMath, cURL, GD, Mbstring, MySQLi, PDO MySQL, PCNTL, ZIP, OPcache, Redis, XML/SimpleXML, and the process-control support required by queue, timer, and Workerman.

Composer dependency installation will run in an isolated build stage. The final image must contain the generated vendor tree but does not need Composer at runtime. Build scripts must run the normal `post-autoload-dump` discovery and publish commands under PHP 8.2 before the image can succeed.

Application code, Composer dependencies, and public files remain image-backed. Runtime data and uploads remain writable mounts. No database or upload directory is copied into an image.

## Dependency Migration

`composer.json` will constrain PHP to `~8.2.0` and enable Composer platform checking. The committed project configuration must not disable advisory reporting, ignore PHP requirements, or ignore required extensions.

The targeted lock update is limited to this compatibility set:

| Package | Current | Compatibility target |
| --- | --- | --- |
| `markbaker/complex` | `1.4.8` | `3.0.2` |
| `markbaker/matrix` | `1.2.0` | `3.0.1` |
| `paragonie/random_compat` | `9.99.99` | `9.99.100` |
| `phpoffice/phpspreadsheet` | `1.13.0` | `1.30.6` |
| `spatie/macroable` | `1.0.0` | `1.0.1` |
| `symfony/process` | `4.3.2` | `4.4.44` |
| `symfony/psr-http-message-bridge` | `1.2.0` | `1.0.2` |
| `symfony/translation` | `4.3.2` | `4.4.47` |
| `symfony/translation-contracts` | `1.1.5` | `2.5.4` |
| `symfony/var-dumper` | `4.3.1` | `4.4.47` |

The expected transitive additions are `composer/pcre 3.4.0`, `ezyang/htmlpurifier 4.19.0`, and `symfony/polyfill-php80 1.37.0`. ThinkPHP, Think Queue, and EasyWechat must remain at their current locked versions unless an unexpected hard conflict is documented and the design is re-approved.

Because current root constraints include packages blocked by modern Composer security policy, a temporary, uncommitted diagnostic setting may be required to calculate the targeted compatibility lock. The resulting lock must be reviewed package by package. The baseline and resulting Composer audits must both be recorded, and the advisory count or severity must not increase.

No `vendor/` file may be patched manually. The final image build and runtime verification may not use `--ignore-platform-reqs` or an equivalent bypass.

## Application Compatibility Changes

The web entrypoint will accept PHP 8.2 and reject versions below 8.2 or at and above the next unvalidated release line. Its error text will describe the actual supported range.

Each optional-before-required declaration will be reviewed with CodeGraph caller data and direct source inspection. The preferred repair is to remove an ineffective earlier default where PHP 8 already treats that parameter as required. Parameters will not be reordered merely to silence a deprecation because positional callers would change meaning.

If an inherited signature or a real caller relies on omission, the declaration and its callers must be handled as one tested change. Unrelated method cleanup and formatting are excluded.

PhpSpreadsheet is directly used by the application's spreadsheet services. Its update must preserve import, export, generated-file, cell-formatting, and image behavior. JWT encode/decode, service discovery, WeChat service construction, and upload provider construction need compatibility smoke tests even though their major dependencies are not upgraded.

## Failure Handling

- Dependency resolution that changes packages outside the approved set stops the migration for review.
- A missing extension, failed Composer platform check, failed autoload script, PHP warning, deprecation, or fatal error stops the image build or acceptance run.
- No warning suppression will be introduced to make PHP 8.2 checks pass.
- Third-party calls that need real credentials remain disabled in automated acceptance. Tests verify local construction and request preparation without sending transactions.
- Logs are scanned after exercised workflows for `Fatal error`, `TypeError`, `Deprecated`, and repeating worker restarts.

## Verification Design

The migration begins by recording the existing failures: PHP 8 entrypoint rejection, the ten locked dependency blockers, the vendor parse failure, and the 32 application deprecations. These are the red baselines for the compatibility work.

A dedicated PHP 8.2 verification script will extend the existing optimized-runtime checks. It must verify:

- the running PHP release is in the 8.2 line;
- required extensions and OPcache are loaded;
- Composer platform requirements pass without bypasses;
- application and vendor lint complete without parse errors or deprecations;
- `php think list`, service discovery, and vendor publish complete;
- PHP-FPM, queue, timer, and Workerman remain `RUNNING`;
- homepage, admin entry, SPA fallback, and representative public APIs respond successfully;
- `/notice` and `/msg` reach Workerman without 502 responses;
- JWT encode/decode works with neutral test claims;
- spreadsheet creation and reading work with neutral temporary data, including an image case;
- local uploads remain writable by `www-data`;
- installer access remains blocked;
- post-exercise logs contain no PHP compatibility failures.

The existing 19 optimized-runtime checks remain mandatory. The data checks remain:

- 157 database tables;
- 4 products;
- 2 users;
- 6 DIY pages;
- 92 group-data rows;
- 25,210 language rows;
- 174 uploaded files;
- 21,664,281 uploaded bytes.

## Cutover and Rollback

The PHP 8.2 image is built and statically verified before any running container is changed. Before integration cutover, create a consistent database dump, record upload counts and bytes, and tag the current `40441143` PHP 7.4 image as the rollback checkpoint.

After preflight succeeds, recreate the application containers without `-v`. MySQL data, Redis state, the runtime volume, and uploads are retained. No migration command or schema mutation is part of this change.

If health checks, application checks, process checks, log checks, or data counts fail, restore the PHP 7.4 checkpoint image. Database rollback is unnecessary because this change performs no schema or data migration. The database dump remains a defensive recovery artifact, not the normal rollback mechanism.

## Acceptance Criteria

The change is accepted only when all of the following are true:

1. The optimized stack runs PHP 8.2 with the required extension set and OPcache.
2. Composer platform checks and targeted dependency review pass without committed bypasses.
3. Application and vendor compatibility checks report no parse failures or PHP 8.2 deprecations.
4. PHP-FPM, queue, timer, Workerman, HTTP, SPA, and WebSocket verification pass.
5. JWT, spreadsheet, and local-upload compatibility smoke tests pass.
6. The recorded database and upload baselines are unchanged.
7. A tested PHP 7.4 image rollback path remains available.
8. Documentation states that unresolved dependency advisories and abandoned SDKs still block public production launch.

## Follow-Up Changes

After PHP 8.2 compatibility is stable, production hardening continues as separate changes:

1. Upgrade JWT and update token encode/decode usage.
2. Migrate Guzzle, PSR-7, EasyWechat, and cloud/payment SDK integrations in bounded groups.
3. Remove or replace abandoned dependencies and eliminate actionable Composer advisories.
4. Complete TLS, production secrets, backup retention, restore drills, and remaining application security hardening.
