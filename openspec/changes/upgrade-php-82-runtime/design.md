## Context

The optimized acceptance runtime built by `optimize-container-runtime` runs PHP 7.4.33 on Debian 11 from the vendor image `ccr.ccs.tencentyun.com/crmebky_php/php:v7.4`. PHP 7.4 has received no security patches since November 2022.

An earlier design was approved on 2026-07-29 from an unmeasured reading of the dependency set. Its evidence has now been reproduced directly against PHP 8.2.32 with `bcmath`, `zip`, `gd`, `pcntl`, `mysqli`, `pdo_mysql`, `simplexml`, and `sockets` installed. The ten locked dependency blockers and the 32 application parameter deprecations reproduce exactly. Three other findings did not, and they change the work:

- The targeted dependency update closes. `composer prohibits php 8.2 --locked` returns no remaining package and `check-platform-reqs` succeeds on every entry with no bypass, while `overtrue/wechat 3.3.33` and `doctrine/cache v1.4.4` stay at their locked versions. The earlier concern that the WeChat dependency chain would force a major EasyWechat upgrade was wrong.
- Composer's default partial update produces 23 changes, 3 additions, and 2 removals, but `--minimal-changes` proves that only 10 updates plus 3 additions are required. The migration uses that smaller result.
- Vendor parse failures are 25 across 6 packages, and two are reachable in production paths.

The earlier baseline was measured with `find … | xargs php -l`. Because `php -l` exits 255 on a fatal parse error, `xargs` aborts the whole batch and silently skips the rest of the tree. That is why 25 failures were recorded as one. Every lint pass in this change lints one file per process and continues past failures.

The vendor tree also does not match `composer.lock`: `crmeb/easypay`, `xin/container`, and `xin/helper` appear in both the lock and `installed.json` with install paths absent from disk and from the running container, while `phpoffice/phpexcel` exists without lock or installed metadata. `topthink/framework v6.1.2` still declares `const VERSION = '6.1.1'` after a clean install, so the source constant is an upstream versioning defect rather than evidence of local drift. Measurements are re-taken on an isolated clean tree before anything is changed.

## Goals / Non-Goals

**Goals:**

- Run the optimized acceptance runtime on the PHP 8.2 line with the current extension capabilities and OPcache.
- Apply the measured dependency delta with platform checks enabled and no committed bypass.
- Repair the application and vendor compatibility failures that are reachable at runtime.
- Preserve the acceptance database, uploads, and a tested PHP 7.4 rollback image.
- Keep animated-GIF upload behavior byte-identical to the PHP 7.4 baseline.

**Non-Goals:**

- Major-version migration of JWT, Guzzle, PSR-7, EasyWechat, COS, payment, SMS, or cloud-storage SDKs.
- Resolving the remaining 33 dependency advisories across 11 packages or replacing the abandoned EasyWechat package.
- Live payment, refund, SMS, WeChat, or cloud-storage transactions.
- Database schema changes or data migrations.
- TLS, production secrets, backup retention, restore drills.
- Repairing the pre-existing static-GIF frame bug described under Known Issues.
- Any claim that the resulting stack is ready for public production.

## Decisions

### Rebuild the Composer tree before measuring anything

The first task installs the unchanged lock into an empty vendor directory under PHP 7.4, where all locked PHP constraints are valid. That immutable vendor tree is then mounted into the PHP 8.2 probe for red-baseline measurements. A diagnostic `--ignore-platform-req=php` install in an isolated container is acceptable only if the PHP 7.4 Composer environment is unavailable; it must never change the lock or enter production commands. Installed versions and source references, `installed.json`, and every recorded install path must agree. The 19 `phpoffice/phpexcel` parse failures disappear because that package is absent from both the lock and `installed.json`, is not referenced by any autoload map, and is named only inside comments in `crmeb/services/SpreadsheetExcelService.php`. The two `think-image` failures remain.

### Build from the official PHP 8.2 image and install Supervisor explicitly

The image derives from `php:8.2-fpm-bookworm`. The current vendor image supplies `bcmath ctype curl dom fileinfo filter ftp gd hash iconv json libxml mbstring mysqli openssl pcntl pcre PDO pdo_mysql posix readline redis session SimpleXML sodium SPL tokenizer xml xmlreader xmlwriter OPcache zip zlib`, and additionally ships Supervisor at `/usr/bin/supervisord`. The Dockerfile installs the missing compiled set, the Redis extension via PECL, and Supervisor from apt. `sockets` is added for Workerman. An isolated Composer stage receives only `composer.json` and `composer.lock`, installs the reviewed lock without scripts, then runs the two static Composer scripts after application code is copied. The final image copies that clean vendor tree after the application copy, and `.dockerignore` excludes `crmeb/vendor/`, so tracked local vendor drift cannot enter the image.

### Disable JIT

`opcache.jit` defaults to a tracing JIT on the PHP 8 line. Queue, timer, and Workerman are long-lived processes where tracing JIT has known stability problems, and JIT offers nothing for this workload. The INI sets `opcache.jit=disable` explicitly rather than relying on a default.

### Apply the minimal 13-package delta

`composer.json` constrains `php` to `~8.2.0` and enables `platform-check`. The update targets the ten blockers with `--with-all-dependencies --minimal-changes`, producing:

Changed: `markbaker/complex` 1.4.8→3.0.2, `markbaker/matrix` 1.2.0→3.0.1, `paragonie/random_compat` v9.99.99→v9.99.100, `phpoffice/phpspreadsheet` 1.13.0→1.30.6, `spatie/macroable` 1.0.0→1.0.1, `symfony/process` v4.3.2→v4.4.44, `symfony/psr-http-message-bridge` v1.2.0→v1.0.2, `symfony/translation` v4.3.2→v4.4.47, `symfony/translation-contracts` v1.1.5→v2.5.4, `symfony/var-dumper` v4.3.1→v4.4.47.

Added: `composer/pcre` 3.4.0, `ezyang/htmlpurifier` v4.19.0, `symfony/polyfill-php80` v1.37.0.

Removed: none.

`symfony/psr-http-message-bridge` moves down from v1.2.0 to v1.0.2 because v1.2.0 requires `http-foundation ^3.4||^4.0` while EasyWechat requires `~2.6|~2.7|~2.8|~3.0`; v1.0.2 satisfies both. ThinkPHP, Think Queue, EasyWechat, JWT, Guzzle, and unrelated transitive packages stay at their locked versions. Any resolution outside this list stops the change for review.

Computing this lock needs Composer 2.10's command-only `--no-blocking` option because Guzzle 6.5.5 is advisory-blocked by current policy. No advisory setting is written to `composer.json`. Baseline and resulting audits are both recorded and the advisory count must not increase.

### Override the two unpatchable vendor classes from project code

`vendor/` is not edited. Two classes cannot run on PHP 8.2 and are reachable:

`topthink/think-image` v1.0.7 uses curly-brace offset access, removed in PHP 8.0, at `gif/Decoder.php:165` and 28 places in `gif/Encoder.php`. `crmeb/services/upload/storage/Local.php:263,341,365` and `crmeb/utils/DownloadImage.php:111` call `Image::open()` on local uploads, and `Image.php:78` constructs the GIF decoder whenever the file type is `gif`. Because this is a compile-time error rather than an exception, the `catch (\Throwable $e)` blocks around those calls never execute; a GIF upload returns HTTP 500 with nothing logged.

The repair is a mechanical `$expr{offset}` → `$expr[offset]` conversion, 1 replacement in `Decoder.php` and 28 in `Encoder.php`. Verified on PHP 8.2 against the PHP 7.4 baseline, output is byte-identical across twelve cases: PNG thumb/water 130/205 bytes, static-GIF thumb/water 93/182, animated-GIF thumb/water 429/717 with all three frames retained, and six text-watermark combinations covering positions 1/5/9 with negative offsets and rotation at 2041/2025/1860/635/630/613 bytes.

`intervention/image` 2.7.2 is already present via `fastknife/ajcaptcha` and is PHP 8.2 compatible, but its GD decoder reads only the first GIF frame. Substituting it would silently flatten every animated GIF, so it is rejected.

`overtrue/wechat` `Support/Collection.php:354` declares `__set_state()` with the wrong argument count, a compile-time failure. The project copy implements `public static function __set_state(array $state)` and returns a reconstructed collection without using `$this`. It also provides PHP 8.2-compatible `ArrayAccess`, `Countable`, `IteratorAggregate`, and `JsonSerializable` return types plus `__serialize()` and `__unserialize()` while retaining `serialize()` and `unserialize()` for compatibility. This removes the eight deprecations that appear as soon as the corrected class can load.

Both repairs ship as project-owned classes registered ahead of the package through Composer `autoload.exclude-from-classmap` plus a project `classmap`, so `composer install` cannot overwrite them and they stay in version control.

### Repair application declarations without reordering parameters

The 32 optional-before-required declarations span 26 files, 14 of them `Dao` methods with the shape `(string $field = '*', int $page, int $limit)`. The repair removes the ineffective default, since PHP 8 already treats those parameters as required. Reordering is rejected because positional callers would change meaning. Fifteen distinct diagnostics are confirmed as reached at runtime; the remainder are repaired identically. `crmeb/utils/Rsa.php:120` replaces `openssl_free_key()`, which is a no-op from PHP 8.0.

### Override reachable vendor return-type blockers

The initial class-loading probe reported 8 return-type notices in `think\Model` and `Pimple\Container`. A real PHP 8.2 image build then failed before `service:discover`: ThinkPHP's error initializer forces `E_ALL` and promotes `think\Paginator::offsetExists()` to an exception. Explicitly loading the reachable interface implementations exposes 18 notices across `think\Paginator` (6), `think\Model` and its `Conversion` trait (4), `think\db\Where` (4), and `Pimple\Container` (4).

These cannot remain known issues because they stop application and console startup. Project-owned copies add the PHP 8.2 interface return types and are registered ahead of their packages through the same `exclude-from-classmap` plus project classmap mechanism used by the GIF and WeChat repairs. Global deprecation suppression and changes to ThinkPHP's error handler are rejected because they would hide new application defects. The acceptance criterion is zero reachable runtime deprecations under `E_ALL`.

Composer's authoritative map is not itself the production reachability boundary. It contains dependency test suites and optional adapters for PHPUnit, Laravel, React, and Symfony components that are not installed in this application. Eagerly loading all 5817 mapped symbols produces missing-development-dependency failures and third-party SDK diagnostics unrelated to CRMEB startup. The verifier therefore covers every application and project-owned symbol, explicitly covers the strict startup classes above, and exercises external SDK entrypoints through separate no-side-effect construction probes.

## Risks / Trade-offs

**A dependency changes outside the measured delta.** The delta was computed on an unverified vendor tree. Recomputing after clean reinstall may shift it. Mitigation: the delta is recomputed and compared before the lock is committed; deviation stops the change.

**PhpSpreadsheet moves 1.13.0 to 1.30.6, 17 minor versions.** It backs order and product export in `app/adminapi/controller/v1/order/StoreOrder.php` and `.../product/StoreProduct.php` through `crmeb/services/SpreadsheetExcelService.php` and `crmeb/services/FileService.php`. Mitigation: export and read checks including a formatting and an image case.

**Workerman 3.5.19 and crontab 1.0.2 declare no meaningful PHP constraint.** Their PHP 8.2 behavior is unverified, and `startsecs=3` lets a crash-restart loop still report `RUNNING`. Mitigation: verification asserts Supervisor restart counts stay at zero, not just process state. The README's instruction to disable `pcntl_*` and `proc_open` is re-confirmed against the 8.2 image, since Workerman needs `pcntl` to fork.

**`post-autoload-dump` runs `think service:discover` and `vendor:publish` during an image build with no database available.** Mitigation: confirm both are static; if either touches the database, run them at container start instead.

**The rollback image shares the `tianli/crmeb-php:acceptance` tag.** A build would overwrite it. Mitigation: retag the current PHP 7.4 image as `tianli/crmeb-php:php74-rollback` before the first 8.2 build.

**Hard-coded acceptance data baselines drift.** The recorded 25,210 language rows, 174 uploads, and 21,664,281 bytes change as soon as anyone uses the environment, and uploads are a live bind mount. Mitigation: snapshot before migration and compare against that snapshot rather than literals.

## Known Issues Carried Forward

- `think-image` `gif/Encoder.php:77` emits `Undefined array key 0` for static GIFs. Present on PHP 7.4 as a Notice and unrelated to this migration; output bytes are unchanged. It will fail any "no warnings in logs" assertion, so that assertion excludes this one site. Repairing it means changing frame-array logic, which is a functional fix, not a compatibility one.
- 33 dependency advisories across 11 packages after migration, reduced from the 62-advisory, 12-package baseline, plus the abandoned EasyWechat package.
- No first-party automated test suite.

## Migration and Rollback

The 8.2 image is built and verified statically before any running container changes. Before cutover: dump the database, snapshot upload count and bytes, and retag the PHP 7.4 image as the rollback checkpoint. Application containers are then recreated without `-v`, preserving MySQL data, Redis state, the runtime volume, and uploads.

If health, application, process, log, or data checks fail, restore the retagged PHP 7.4 image. No schema or data migration occurs, so no database rollback is needed; the dump is a defensive artifact only.
