## Why

The optimized acceptance runtime is on PHP 7.4.33, which reached end of life in November 2022 and receives no security patches. The prior design (`docs/superpowers/specs/2026-07-29-php-82-compatibility-design.md`) approved a PHP 8.2 compatibility migration on evidence that has now been measured directly. Three of its findings were wrong or incomplete, and the corrections change the shape of the work.

Measured on 2026-07-29 against a PHP 8.2.32 container with the extensions the application requires:

- The ten `composer prohibits php 8.2 --locked` blockers reproduce exactly, and a targeted lock update clears them: `prohibits` reports no remaining package, and `check-platform-reqs` passes on every entry without a bypass. The `overtrue/wechat` and `doctrine/cache` versions are retained unchanged. The prior concern that this dependency set could not close was incorrect.
- Composer's default partial update changes 23 packages, adds 3, and removes 2. Re-running the same update with `--minimal-changes` produces the smaller compatible result: 10 updates, 3 additions, and no removals. Both clear the PHP 8.2 platform gates; the migration selects the minimal result and leaves the broader dependency refresh out of scope.
- Vendor PHP 8.2 parse failures are 25 across 6 packages, not the single Doctrine Cache failure recorded. Two of them are reachable in production paths. Application code (917 files under `app/`, 158 under `crmeb/`, plus `config/`, `route/`, `public/`) has zero parse failures.
- `topthink/think-image` v1.0.7 fails to compile on PHP 8.2 in `gif/Decoder.php` and `gif/Encoder.php`. `crmeb/services/upload/storage/Local.php` and `crmeb/utils/DownloadImage.php` call `Image::open()` on every local upload. Because curly-brace offset access is a compile-time error, the surrounding `catch (\Throwable $e)` never runs: uploading any GIF returns an unlogged HTTP 500. This package declares no `php` constraint, so `composer prohibits` cannot see it.
- `overtrue/wechat` `Support/Collection.php:354` declares `__set_state()` with the wrong arity and is a compile-time failure on the WeChat path. A one-parameter edit is insufficient: PHP 8.2 also requires the method to be static, its current body cannot use `$this` in static context, and the class emits eight interface/serialization deprecations after it compiles.
- Runtime deprecations, measured by loading all 1042 autoloadable classes under `E_ALL` (0 load failures): 24 distinct diagnostics in application code, all from the 32 optional-before-required declarations, and 8 in `think-orm` plus `Pimple` that remain after the project-owned WeChat collection is fully repaired. The prior acceptance criterion requiring zero vendor deprecations is therefore unreachable as written.
- The vendor tree does not match `composer.lock`: `crmeb/easypay`, `xin/container`, and `xin/helper` are recorded in both the lock and `installed.json` with install paths that do not exist, while an unrecorded `phpoffice/phpexcel` directory remains. The `topthink/framework v6.1.2` source itself still declares `const VERSION = '6.1.1'`, including after a clean install, so that constant is not used as an integrity check.

## What Changes

- Rebuild the original Composer tree from the lock in an isolated PHP 7.4 environment, then probe that immutable tree with PHP 8.2. The red baseline never changes `composer.lock`.
- Build the PHP runtime from `php:8.2-fpm-bookworm`, installing the extension set the current image provides plus Supervisor and using an isolated Composer stage so the image never copies the repository's tracked `vendor/` tree.
- Constrain the Composer platform requirement to the PHP 8.2 line, enable platform checks, and apply the minimal 10-update, 3-addition lock delta.
- Repair the 32 optional-before-required declarations in application code without reordering parameters.
- Replace the two unpatchable `think-image` GIF classes with project-owned equivalents loaded ahead of the package, keeping animated-GIF frame handling intact. `intervention/image` 2.7.2 is already in the tree but decodes only the first GIF frame, so it is not a usable substitute.
- Repair the `overtrue/wechat` collection's PHP 8.2 magic-method, interface-return, and serialization contract through the same project-owned override mechanism rather than editing `vendor/`.
- Replace `openssl_free_key()` in `crmeb/utils/Rsa.php`, which is deprecated from PHP 8.0.
- Allow the web entrypoint to run on PHP 8.2 and reject unvalidated release lines.
- Add PHP 8.2 build, dependency, lint, deprecation, process, HTTP, WebSocket, JWT, spreadsheet, image, upload, and data-preservation checks, and fix the lint-harness defect described below.
- Record the vendor deprecations and the pre-existing `Encoder.php:77` warning as accepted known issues rather than release blockers.
- **BREAKING**: the runtime moves to the PHP 8.2 line; the PHP 7.4 image is retained only as a rollback checkpoint.
- This remains an acceptance-environment compatibility migration. The lock migration reduces the audit from 62 advisories across 12 packages to 33 across 11 packages, but the remaining advisories, the abandoned EasyWechat package, and the absent first-party suite still block public production launch.

## Capabilities

### New Capabilities

- `php-82-runtime`: A PHP 8.2 application image, a platform-checked dependency set, application and vendor compatibility repairs, and reproducible compatibility verification.

### Modified Capabilities

- `optimized-container-runtime`: the PHP image base, extension provisioning, and process supervision move to PHP 8.2. All existing runtime, health, isolation, and data-preservation requirements continue to apply unchanged.

## Impact

- Affected code: `docker/php/Dockerfile`, `docker/php/opcache.ini`, `crmeb/composer.json`, `crmeb/composer.lock`, `crmeb/public/index.php`, `crmeb/utils/Rsa.php`, 26 files carrying optional-before-required declarations, and new project-owned image and WeChat compatibility classes.
- Affected verification: `help/docker/verify-optimized.ps1` and a new PHP 8.2 verification script.
- Preserved state: `help/docker/mysql/data` and `crmeb/public/uploads`. No schema or data migration is part of this change.
- Known issues carried forward: 8 vendor runtime deprecations, the pre-existing static-GIF `Undefined array key 0` warning, and the outstanding dependency advisories.

## Verification Harness Defect

The prior baseline was measured with `find … | xargs php -l`. `php -l` exits 255 on a fatal parse error, which makes `xargs` abort the entire batch and skip every remaining file. That is how a tree with 25 failures was recorded as having one. Both the baseline and the acceptance script must lint one file per process and continue past failures.
