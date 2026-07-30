## 1. Verification baseline

- [x] 1.1 Add deterministic checks that inventory project-owned license surfaces while excluding dependencies, generated directories, historical specifications, and third-party license/NOTICE files.
- [x] 1.2 Add byte-preservation fixtures for the exact restrictive-header replacement and record the expected current match set before writing any source file.
- [x] 1.3 Add focused failing checks for commercial authorization routes/UI, installer telemetry, forced CRMEB branding fallbacks, active published assets, and fresh-install metadata.
- [x] 1.4 Add a network-isolated upgrade probe covering side-effect-free construction, the default-disabled capability, the stable disabled response, HTTPS validation, token-read observation, and local cross-version operations.

## 2. Project-owned license and installer alignment

- [x] 2.1 Replace only the exact restrictive line in tracked project-owned source with `Licensed under the Apache License, Version 2.0`, preserving every other byte plus copyright and author attribution.
- [x] 2.2 Make `crmeb/LICENSE.txt` and `help/resource/LICENSE.txt` complete Apache-2.0 copies while leaving third-party licenses and NOTICE files unchanged.
- [x] 2.3 Replace the proprietary installer agreement with an Apache-2.0 notice covering permitted use, attribution/change notices, warranty, trademark limits, and the bundled full license.
- [x] 2.4 Remove installer completion telemetry and verify the success page performs only local rendering and navigation.
- [x] 2.5 Run the focused license tests, PHP syntax checks, byte/count checks, and third-party attribution checks before staging the mechanical changes.

## 3. Commercial authorization removal

- [x] 3.1 Remove `check_auth`, `auth_apply`, and `auth` routes plus their commercial controller/service methods, while preserving `/copyright` and ordinary authentication/authorization middleware.
- [x] 3.2 Remove the dashboard automatic authorization check, authorization API wrappers, application/purchase/price/payment UI, and CRMEB marketplace iframe.
- [x] 3.3 Verify callers and remove only the legacy authorization/telemetry upgrade clients identified by the approved implementation plan.
- [x] 3.4 Verify former commercial endpoints return not found, no fabricated authorization result is returned, and dashboard business metrics remain available.

## 4. Local system information and copyright

- [x] 4.1 Convert the former commercial-authorization page to local system information while retaining environment/directory diagnostics and the existing local copyright editor.
- [x] 4.2 Update menu ID `605` in place to the neutral system-information title, route, and permission so existing role ownership is preserved.
- [x] 4.3 Preserve the existing `/copyright` URLs, configuration keys, stored values, status envelope, and response field names; update runtime and fresh-install route metadata to `读取版权信息` and `保存版权信息`.
- [x] 4.4 Remove forced CRMEB text, URL, and image fallbacks from management, login, customer-service, PC, H5, and mini-program consumers without adding a product-wide rebrand; delete the unused uni-app support image only after all source references are gone.
- [x] 4.5 Verify configured copyright text/image render and empty or failed configuration renders no CRMEB fallback.

## 5. Default-off official remote upgrade

- [x] 5.1 Add `Env::get('upgrade.remote_enabled', false)` and document/deploy `UPGRADE_REMOTE_ENABLED=false` without editing `crmeb/.env`.
- [x] 5.2 Make upgrade-service construction side-effect free and defer `.version`, request-host, `upgrade_auth_token`, and authentication reads until after one shared remote-enabled gate.
- [x] 5.3 Gate every official authentication, list, agreement, status, package download, and log method before HTTP; disabled calls return business `status=400` with `官方在线升级服务未启用`.
- [x] 5.4 Add `GET /system/upgrade/capability` as a local authenticated endpoint returning business `status=200` and boolean `data.remote_enabled`.
- [x] 5.5 Make the management online-upgrade page capability-first and stop all other remote-upgrade calls while disabled.
- [x] 5.6 Read official endpoints only from configuration, require HTTPS, record a management log before rejecting invalid or missing HTTPS configuration, and preserve existing authentication, signature, and eligibility behavior when explicitly enabled.
- [x] 5.7 Verify under network isolation that disabled construction and calls read no cached token, contact no CRMEB domain, and return the specified local responses.

## 6. Independent local upgrades

- [x] 6.1 Keep local cross-version inspection, pending-version inspection, and SQL preview outside the official remote gate.
- [x] 6.2 Keep local database/project backup, execution, progress, rollback inspection, and rollback execution outside the official remote gate.
- [x] 6.3 Verify all local operations remain available with remote upgrade disabled and without remote login or external requests.

## 7. Narrow install and existing-environment cleanup

- [x] 7.1 Remove only `check_auth`, `auth_apply`, `auth`, `cert_crmeb`, authorization-only translations, deleted authorization/telemetry file inventory, obsolete authorization menu metadata, and the fresh-install file-tree rows for `/public/static/images/support.png` and `/public/statics/mp_view/static/images/support.png`.
- [x] 7.2 Recalculate integrity entries only for retained files whose final bytes changed; do not disable or broadly regenerate integrity metadata from an unverified tree.
- [x] 7.3 Add a transactional, table-prefix-aware, idempotent existing-environment cleanup limited to the named configuration, route, translation, menu, file-inventory, and MD5 records.
- [x] 7.4 Delete `upgrade_auth_token` only when official remote upgrade is disabled, after the database transaction succeeds.
- [x] 7.5 Run cleanup twice and verify the second run removes no additional authorization records.
- [x] 7.6 Compare deterministic before/after digests for products, orders, users, attachments/uploads, homepage decoration, and demo data and require exact equality.

## 8. Build and acceptance

- [x] 8.1 Run focused compliance checks, PHP syntax/tests, upgrade probes, migration tests, and `openspec validate align-apache-license-and-disable-vendor-auth --strict --no-interactive`.
- [x] 8.2 Build management source plus fresh H5 (`unpackage/dist/build/web`) and `mp-weixin` trees with one fixed official HBuilderX/uni-app compiler version, recording the compiler/plugin versions and hashes plus exact commands. Use `publish web`/`publish mp-weixin`, or only when those wrappers are blocked by tracked DCloud AppID membership, the same installation's official local `uniapp-cli` compiler with explicit production input/output/platform variables and no upload; do not modify the tracked manifest or invent a uni-app npm build script.
- [x] 8.3 Synchronize all three outputs through separately validated staging directories: mirror management only to `crmeb/public/admin`, the verified `build/web` H5 `static/` only to `crmeb/public/static` plus H5 `index.html` only to `crmeb/public/index.html`, and the complete mini-program tree only to `crmeb/public/statics/mp_view`; never mirror an H5 root over `crmeb/public`, hand-edit minified output, or broadly regenerate the pre-existing legacy `system_file_info` client-asset inventory.
- [x] 8.4 Verify active source and all three published surfaces contain no commercial authorization call/UI, installer telemetry, or forced CRMEB branding fallback, and verify `template/uni-app/static/images/support.png`, `crmeb/public/static/images/support.png`, and `crmeb/public/statics/mp_view/static/images/support.png` are absent.
- [x] 8.5 Exercise dashboard, system information, configured/empty copyright, disabled online upgrade, and local cross-version workflows while recording network requests and business response statuses.
- [ ] 8.6 Run existing PHP 8.2/runtime acceptance and compare commerce-table and upload evidence before marking implementation tasks complete.
- [x] 8.7 Review every staged path and confirm third-party licenses, `.env`, `.constant`, credentials, unrelated dirty files, business data, uploads, and unapproved artifacts are absent.

## Recorded verification state

Evidence refreshed on 2026-07-31 against `f2014a83` and the current uncommitted release-asset worktree:

- `LicenseAlignment.Tests.ps1`: 7 passed, 0 failed.
- `verify-license-alignment.ps1`: 6 areas passed; restrictive project-owned headers `old=0`, aligned headers `new=1239`, total `1239`.
- Network-isolated `upgrade-remote-policy.php`: `status=pass`; capability, stable business errors, one warning per invalid endpoint, zero token reads/HTTP requests, and all listed local upgrade operations passed.
- PHP 8.2 syntax: all 1,109 PHP files below `app`, `crmeb`, `config`, and `upgrade` reported no syntax errors.
- Strict OpenSpec validation passed, and the published-asset area confirmed all three support images are absent.
- Two final `license:cleanup` runs both reported zero authorization/configuration/translation/file-inventory deletions and rebuilt the local MD5 baseline successfully.
- Authenticated browser acceptance ran from 02:44:53 through 03:06:05 Asia/Shanghai. Dashboard metrics and System Information loaded; configured copyright saved and was restored to empty; the former authorization routes returned HTTP 404; online upgrade called only the local capability endpoint; local cross-version endpoints returned business `status=200`; and the storefront rendered three demo products.
- Fixed builds were produced with the pinned Admin Node image and HBuilderX `5.15.2026070915`/`uniapp-cli` `5.15.2026070717.2935`. Admin and H5 publication bytes match retained build outputs. The first `mp-weixin` build matches publication byte-for-byte; a second build differs in eight JSON files only by object-key order, and structured JSON comparison reports zero semantic differences.
- Both running web containers match all 2,031 published files by path, byte count, and SHA-256. Production deployment must build and recreate both PHP and Nginx images from the same release tree and clear PHP template/OPcache state.
- After the final cleanup, products, orders, users, attachments, homepage decoration, three visible demo products, and all 174 upload files remain strictly equal to the pre-change baseline.
- Final staged-path review covered 1,056 no-rename paths: unexpected paths `0`, sensitive/credential/unapproved paths `0`, and third-party LICENSE/NOTICE changes `0`. The generated-tree whitespace attributes preserve compiler bytes while `git diff --cached --check` exits `0`; the user's `*.sh text eol=lf` rule remains unstaged.

The remaining evidence gate is existing PHP 8.2/runtime acceptance (8.6). `verify-php82.ps1 -Mode Integrity` currently reports 54 `composer.lock`/installed-vendor mismatches; this belongs to the separately excluded PHP 8.2 dependency work and is not changed by this publication commit.
