## Context

The optimized acceptance runtime built by `optimize-container-runtime` runs PHP 7.4.33 on Debian 11 from the vendor image `ccr.ccs.tencentyun.com/crmebky_php/php:v7.4`. PHP 7.4 has received no security patches since November 2022.

An earlier design was approved on 2026-07-29 from an unmeasured reading of the dependency set. Its evidence has now been reproduced directly against PHP 8.2.32 with `bcmath`, `zip`, `gd`, `pcntl`, `mysqli`, `pdo_mysql`, `simplexml`, and `sockets` installed. The ten locked dependency blockers and the 32 application parameter deprecations reproduce exactly. Three other findings did not, and they change the work:

- The targeted dependency update closes. `composer prohibits php 8.2 --locked` returns no remaining package and `check-platform-reqs` succeeds on every entry with no bypass, while `overtrue/wechat 3.3.33` and `doctrine/cache v1.4.4` stay at their locked versions. The earlier concern that the WeChat dependency chain would force a major EasyWechat upgrade was wrong.
- Composer's default partial update produces 23 changes, 3 additions, and 2 removals. `--minimal-changes` initially proves that only 10 updates plus 3 additions are required to clear platform gates; offline integration probes then identify the Xlsx, EasyWechat logging, and JDOSS dependency paths that need compatibility updates. The final migration uses 18 updates and 4 additions: the earlier 12-update, 3-addition result plus six same-major AWS/Guzzle updates and `aws/aws-crt-php`.
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

### Apply the minimal 22-package delta

`composer.json` constrains `php` to `~8.2.0` and enables `platform-check`. The update targets the ten platform blockers with `--with-all-dependencies --minimal-changes`, then adds the integration-proven Xlsx, EasyWechat logging, and JDOSS updates, producing:

Changed: `aws/aws-sdk-php` 3.173.19→3.236.0, `guzzlehttp/guzzle` 6.5.5→6.5.8, `guzzlehttp/promises` v1.3.1→1.5.3, `guzzlehttp/psr7` 1.7.0→1.9.1, `markbaker/complex` 1.4.8→3.0.2, `markbaker/matrix` 1.2.0→3.0.1, `monolog/monolog` 1.24.0→1.27.1, `mtdowling/jmespath.php` 2.5.0→2.9.2, `myclabs/php-enum` 1.7.6→1.8.5, `paragonie/random_compat` v9.99.99→v9.99.100, `phpoffice/phpspreadsheet` 1.13.0→1.30.6, `spatie/macroable` 1.0.0→1.0.1, `symfony/polyfill-mbstring` v1.11.0→v1.38.2, `symfony/process` v4.3.2→v4.4.44, `symfony/psr-http-message-bridge` v1.2.0→v1.0.2, `symfony/translation` v4.3.2→v4.4.47, `symfony/translation-contracts` v1.1.5→v2.5.4, `symfony/var-dumper` v4.3.1→v4.4.47.

Added: `aws/aws-crt-php` v1.2.7, `composer/pcre` 3.4.0, `ezyang/htmlpurifier` v4.19.0, `symfony/polyfill-php80` v1.37.0.

Removed: none.

`symfony/psr-http-message-bridge` moves down from v1.2.0 to v1.0.2 because v1.2.0 requires `http-foundation ^3.4||^4.0` while EasyWechat requires `~2.6|~2.7|~2.8|~3.0`; v1.0.2 satisfies both. ThinkPHP, Think Queue, EasyWechat, JWT, and unrelated transitive packages stay at their locked versions. Any resolution outside this list stops the change for review.

`myclabs/php-enum` 1.7.6 is loaded by `maennchen/zipstream-php` during Xlsx writes and emits a `JsonSerializable::jsonSerialize()` return-type deprecation that ThinkPHP's strict handler promotes to a fatal error. Version 1.8.5 remains on the compatible 1.x line, adds `#[ReturnTypeWillChange]`, and a targeted `--minimal-changes --dry-run` proves that no other package moves.

`monolog/monolog` 1.24.0 is reached by EasyWechat's constructor-time debug logging and passes `null` to `DateTime`, which PHP 8.2 deprecates and the strict handler promotes to a fatal error. Version 1.27.1 remains on the compatible 1.x line, passes `'now'` explicitly, and a targeted `--minimal-changes --dry-run` proves that no other package moves.

`aws/aws-sdk-php` 3.173.19 is reached only after JDOSS resolves its protected `app()` method and creates the underlying `S3Client`; wrapper-only construction misses the defect. Under `E_ALL`, that path emits 15 PHP 8.2 deprecations across callable, `Countable`, `ArrayAccess`, and `Serializable` contracts. Updating AWS alone stops at 3.226.0 because the root pins Guzzle 6.5.5, and that version still emits eight callable deprecations. AWS 3.235.5 is the first release to remove the final callable diagnostics; the reviewed target remains AWS 3.236.0 with Guzzle 6.5.8 and PSR-7 1.9.1. Composer also updates Promises, JMESPath, and the mbstring polyfill and adds AWS CRT. A read-only `--network none` probe constructs `Aws\\S3\\S3Client` with synthetic credentials at zero diagnostics and sends no request. All changes stay within their existing major versions.

Computing this lock needs Composer 2.10's command-only `--no-blocking` option because Guzzle 6.5.5 is advisory-blocked by current policy. No advisory setting is written to `composer.json`. Baseline and resulting audits are both recorded and the advisory count must not increase.

### Override unpatchable reachable vendor classes from project code

`vendor/` is not edited. Two classes cannot run on PHP 8.2 and are reachable:

`topthink/think-image` v1.0.7 uses curly-brace offset access, removed in PHP 8.0, at `gif/Decoder.php:165` and 28 places in `gif/Encoder.php`. `crmeb/services/upload/storage/Local.php:263,341,365` and `crmeb/utils/DownloadImage.php:111` call `Image::open()` on local uploads, and `Image.php:78` constructs the GIF decoder whenever the file type is `gif`. Because this is a compile-time error rather than an exception, the `catch (\Throwable $e)` blocks around those calls never execute; a GIF upload returns HTTP 500 with nothing logged.

The repair is a mechanical `$expr{offset}` → `$expr[offset]` conversion, 1 replacement in `Decoder.php` and 28 in `Encoder.php`. Verification compares output directories generated from one shared PHP 7.4 fixture set instead of relying on fixed byte counts. Nine cases are byte-identical with matching SHA256 and dimensions: PNG thumbnail/image watermark, static-GIF thumbnail/image watermark, animated-GIF thumbnail/image watermark, and GIF text watermark at positions 1/5/9. Both animated-GIF outputs retain all three frames.

The same exercised image path exposes one further PHP 8.2 deprecation in `think-image` `Image.php`: centered text positions can produce half-pixel floats, which `imagettftext()` implicitly converts to integer coordinates. A project-owned copy casts the final x/y arguments explicitly at that API boundary. PHP 7.4 already truncates those values, so this removes the diagnostic without changing the integer coordinates passed to GD.

Text PNG output is not byte-identical across the rollback and candidate graphics stacks: PHP 7.4 uses Debian 11 FreeType 2.10.4/libpng 1.6.37, while PHP 8.2 uses Debian 12 FreeType 2.12.1/libpng 1.6.39. For each of `text_png_1`, `text_png_5`, and `text_png_9`, decoded comparison finds exactly one differing pixel out of 9,600, identical alpha, and a maximum RGB channel delta of 1. The acceptance gate permits only that bounded exception, requires identical dimensions, and proves the boundary with negative fixtures where two changed pixels, RGB delta 2, or any alpha change fail.

`intervention/image` 2.7.2 is already present via `fastknife/ajcaptcha` and is PHP 8.2 compatible, but its GD decoder reads only the first GIF frame. Substituting it would silently flatten every animated GIF, so it is rejected.

`overtrue/wechat` `Support/Collection.php:354` declares `__set_state()` with the wrong argument count, a compile-time failure. The project copy implements `public static function __set_state(array $state)` and returns a reconstructed collection without using `$this`. It also provides PHP 8.2-compatible `ArrayAccess`, `Countable`, `IteratorAggregate`, and `JsonSerializable` return types plus `__serialize()` and `__unserialize()` while retaining `serialize()` and `unserialize()` for compatibility. This removes the eight deprecations that appear as soon as the corrected class can load.

Both repairs ship as project-owned classes registered ahead of the package through Composer `autoload.exclude-from-classmap` plus a project `classmap`, so `composer install` cannot overwrite them and they stay in version control.

### Repair application declarations without reordering parameters

The 32 optional-before-required declarations span 26 files, 14 of them `Dao` methods with the shape `(string $field = '*', int $page, int $limit)`. The repair removes the ineffective default, since PHP 8 already treats those parameters as required. Reordering is rejected because positional callers would change meaning. Fifteen distinct diagnostics are confirmed as reached at runtime; the remainder are repaired identically. `crmeb/utils/Rsa.php:120` replaces `openssl_free_key()`, which is a no-op from PHP 8.0.

### Override reachable vendor return-type blockers

The initial class-loading probe reported 8 return-type notices in `think\Model` and `Pimple\Container`. A real PHP 8.2 image build then failed before `service:discover`: ThinkPHP's error initializer forces `E_ALL` and promotes `think\Paginator::offsetExists()` to an exception. Explicitly loading the reachable interface implementations exposes 18 notices across `think\Paginator` (6), `think\Model` and its `Conversion` trait (4), `think\db\Where` (4), and `Pimple\Container` (4).

These cannot remain known issues because they stop application and console startup. Project-owned copies add the PHP 8.2 interface return types and are registered ahead of their packages through the same `exclude-from-classmap` plus project classmap mechanism used by the GIF and WeChat repairs. Global deprecation suppression and changes to ThinkPHP's error handler are rejected because they would hide new application defects. The acceptance criterion is zero reachable runtime deprecations under `E_ALL`.

Composer's authoritative map is not itself the production reachability boundary. It contains dependency test suites and optional adapters for PHPUnit, Laravel, React, and Symfony components that are not installed in this application. Eagerly loading all 5817 mapped symbols produces missing-development-dependency failures and third-party SDK diagnostics unrelated to CRMEB startup. The verifier therefore covers every application and project-owned symbol, explicitly covers the strict startup classes above, and exercises external SDK entrypoints through separate no-side-effect construction probes.

That application-symbol probe also exposes a Linux-only PSR-4 defect in the WeChat Open Platform path. `ProgramProvider.php`, its registration in `Application.php`, and the on-disk directory all use `Open3rd`, while `AccessToken.php` and `ProgramOpen3rd.php` declare `open3rd`. Composer therefore cannot autoload either class on the case-sensitive PHP image when the provider closures are resolved. The repair changes only those two namespace declarations to the established `Open3rd` spelling and verifies both through Composer autoload; no service behavior or external request is changed.

Resolving the repaired Open Platform provider then reaches `ProgramOpen3rd::__construct()`, which stores `AccessToken::getConfig()` in an undeclared `$config` property. PHP 8.2 reports the dynamic property creation as deprecated. Declaring the existing property is the complete repair; constructor input and downstream behavior remain unchanged.

## Risks / Trade-offs

**A dependency changes outside the measured delta.** The delta was computed on an unverified vendor tree. Recomputing after clean reinstall may shift it. Mitigation: the delta is recomputed and compared before the lock is committed; deviation stops the change.

**PhpSpreadsheet moves 1.13.0 to 1.30.6, 17 minor versions.** It backs order and product export in `app/adminapi/controller/v1/order/StoreOrder.php` and `.../product/StoreProduct.php` through `crmeb/services/SpreadsheetExcelService.php` and `crmeb/services/FileService.php`. Mitigation: export and read checks including a formatting and an image case.

**AWS SDK moves from 3.173.19 to 3.236.0 and requires Guzzle/PSR-7 patch movement.** Live JDOSS requests need credentials and would create third-party side effects. Mitigation: keep every package on its existing major line, construct the real lazy `S3Client` with synthetic local configuration under `--network none`, and defer live upload/download transactions to the third-party verification phase.

**Workerman 3.5.19 and crontab 1.0.2 declare no meaningful PHP constraint.** Their PHP 8.2 behavior is unverified, and `startsecs=3` lets a crash-restart loop still report `RUNNING`. Mitigation: verification asserts Supervisor restart counts stay at zero, not just process state. The README's instruction to disable `pcntl_*` and `proc_open` is re-confirmed against the 8.2 image, since Workerman needs `pcntl` to fork.

**`post-autoload-dump` runs `think service:discover` and `vendor:publish` during an image build with no database available.** Mitigation: confirm both are static; if either touches the database, run them at container start instead.

**The rollback image shares the `tianli/crmeb-php:acceptance` tag.** A build would overwrite it. Mitigation: retag the current PHP 7.4 image as `tianli/crmeb-php:php74-rollback` before the first 8.2 build.

**Hard-coded acceptance data baselines drift.** The recorded 25,210 language rows, 174 uploads, and 21,664,281 bytes change as soon as anyone uses the environment, and uploads are a live bind mount. Mitigation: snapshot before migration and compare against that snapshot rather than literals.

**Offline class loading does not reach every failure.** This was not a predicted risk; it was observed. Loading all 1036 autoloadable classes under `E_ALL` reported zero diagnostics and all nine offline integration cases passed, yet the first cutover attempt returned HTTP 500 on the homepage and left timer and Workerman `FATAL`. Four defects live only in code paths that require a running FPM worker or a Supervisor-managed long-lived process: Carbon's `jsonSerialize()` return type reached through `think-queue`'s clock helper, `Worker::$workerId` dynamic property creation, `ob_implicit_flush(0)` inside template rendering, and the float microsecond argument to `stream_select()` in Workerman's event loop. Mitigation: static verification gates the image, but process health, route responses, and log scanning against a genuinely running stack remain mandatory before the change can be accepted.

## Known Issues Carried Forward

- `think-image` `gif/Encoder.php:77` emits `Undefined array key 0` for static GIFs. Present on PHP 7.4 as a Notice and unrelated to this migration; output bytes are unchanged. It will fail any "no warnings in logs" assertion, so that assertion excludes this one site. Repairing it means changing frame-array logic, which is a functional fix, not a compatibility one.
- 24 dependency advisories across 9 packages after migration, reduced from the 62-advisory, 12-package baseline, plus two abandoned packages (`guzzle/guzzle` and `topthink/think-factory`).
- No first-party automated test suite.

## Migration and Rollback

The 8.2 image is built and verified statically before any running container changes. Before cutover: dump the database, snapshot upload count and bytes, and retag the PHP 7.4 image as the rollback checkpoint. Application containers are then recreated without `-v`, preserving MySQL data, Redis state, the runtime volume, and uploads.

If health, application, process, log, or data checks fail, restore the retagged PHP 7.4 image. No schema or data migration occurs, so no database rollback is needed; the dump is a defensive artifact only.
