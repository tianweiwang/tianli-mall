## 1. Trustworthy baseline

- [x] 1.1 Install the unchanged lock into an empty vendor directory under PHP 7.4, then mount that immutable tree into the PHP 8.2 probe. Confirm installed versions and source references, `installed.json`, and the lock agree, and that the `crmeb/easypay`, `xin/container`, and `xin/helper` install paths exist. Do not use the stale `topthink/framework` source constant as a version check.
- [x] 1.2 Add a PHP 8.2 verification script that lints one file per process, continues past failures, reports every failing file, and exits nonzero if any failure occurred.
- [x] 1.3 Record the red baseline on the reinstalled tree: entrypoint PHP 8 rejection, `composer prohibits php 8.2 --locked` blockers, vendor parse failures with per-package counts, application parse failures, and application plus vendor runtime deprecations under `E_ALL`.
- [x] 1.4 Recompute the targeted lock delta with `--minimal-changes` on the clean tree and compare against the 10 changed, 3 added, 0 removed set in the design. Stop for review on any deviation.

## 2. PHP 8.2 runtime image

- [x] 2.1 Add the `php:8.2-fpm-bookworm` Dockerfile installing the current extension set plus `sockets`, the Redis extension, and Supervisor. Add an isolated Composer stage, exclude the tracked host `vendor/` from the build context, copy the clean stage output into the final image, and verify the extension list against the PHP 7.4 image.
- [x] 2.2 Add the PHP 8.2 OPcache INI with `opcache.jit=disable` and the existing production error, realpath-cache, and timestamp-validation settings.
- [x] 2.3 Confirm `think service:discover` and `vendor:publish` complete during image build without database access, or move them to container start.
- [x] 2.4 Re-confirm the disabled-function policy against the 8.2 image and that Workerman retains the `pcntl` support it needs to fork.

## 3. Dependency migration

- [x] 3.1 Constrain `composer.json` to `~8.2.0`, enable `platform-check`, and confirm no advisory-ignore, platform-ignore, or extension-ignore setting reaches the committed file.
- [x] 3.2 Apply the reviewed 12-update, 3-addition lock delta package by package with `--minimal-changes`, including the integration-proven `myclabs/php-enum` 1.7.6→1.8.5 and `monolog/monolog` 1.24.0→1.27.1 patch updates, using the diagnostic advisory setting only locally and never committing it.
- [x] 3.3 Confirm `composer prohibits php 8.2 --locked` reports no package and `check-platform-reqs` passes on every entry with no bypass.
- [x] 3.4 Record baseline and resulting audits and confirm the advisory count and severity do not increase.
- [x] 3.5 Prove JDOSS's real lazy `S3Client` emits PHP 8.2 diagnostics on AWS SDK 3.173.19 and that AWS 3.226.0 still emits 8; apply the reviewed AWS 3.236.0, Guzzle 6.5.8, PSR-7 1.9.1, Promises 1.5.3, JMESPath 2.9.2, mbstring polyfill 1.38.2, and AWS CRT 1.2.7 delta, then confirm zero diagnostics and a non-regressing audit. Measured via `help/docker/probes/php82-aws-s3client.php`, which resolves the client through `Jdoss::app()` and additionally builds a `PutObject` command and the S3 v4 signer: 21 diagnostics on 3.173.19, 8 on 3.226.0, 0 on 3.236.0. Audit moves from 33 advisories across 11 packages to 25 across 10, and to 24 across 9 after the runtime-path updates in 4.12.

## 4. Compatibility repairs

- [x] 4.1 Add project-owned replacements for `think-image` `gif/Decoder.php` and `gif/Encoder.php` converting the 29 curly-brace offset accesses, registered ahead of the package through `exclude-from-classmap` and a project classmap so `composer install` cannot overwrite them.
- [x] 4.2 Add a project-owned replacement for `overtrue/wechat` `Support/Collection.php` with a valid static `__set_state()` reconstruction, PHP 8.2-compatible interface return types, and `__serialize()`/`__unserialize()` support, through the same mechanism.
- [x] 4.3 Repair the 32 optional-before-required declarations across 26 files by removing the ineffective default, without reordering parameters, checking callers for each.
- [x] 4.4 Replace `openssl_free_key()` in `crmeb/utils/Rsa.php`.
- [x] 4.5 Allow the entrypoint to run on PHP 8.2, reject unvalidated release lines, and correct the supported-range message.
- [x] 4.6 Add project-owned PHP 8.2 return-type overrides for `think\\Paginator`, `think\\Model` plus its `Conversion` trait, `think\\db\\Where`, and `Pimple\\Container`; register them ahead of vendor code and prove all 18 startup-blocking deprecations are removed without weakening error handling.
- [x] 4.7 Add a project-owned `think\\Image` override that explicitly converts final text-watermark coordinates to integers at the GD call boundary, preserving PHP 7.4 coordinate truncation while removing the PHP 8.2 implicit-conversion deprecation.
- [x] 4.8 Align `AccessToken` and `ProgramOpen3rd` with the existing `Open3rd` directory/provider namespace and prove Composer autoload resolves both classes on the Linux PHP image.
- [x] 4.9 Prove Xlsx writing fails under strict PHP 8.2 diagnostics with `myclabs/php-enum` 1.7.6, update only that package to 1.8.5, and prove the deprecation is removed without changing the advisory count or severity.
- [x] 4.10 Prove EasyWechat construction-time logging fails under strict PHP 8.2 diagnostics with `monolog/monolog` 1.24.0, update only that package to 1.27.1, and prove the deprecation is removed without changing the advisory count or severity.
- [x] 4.11 Prove `ProgramOpen3rd` construction creates a dynamic `$config` property under PHP 8.2, declare the existing property without changing constructor behavior, and prove the diagnostic is removed.
- [x] 4.12 Repair the three long-lived-process and request-path failures that offline class-loading probes could not reach, all of which only appear once PHP-FPM and the Supervisor programs actually run: update `nesbot/carbon` 2.20.0→2.73.0 for its `#[ReturnTypeWillChange]` on `jsonSerialize()`, which otherwise crashes the queue worker through `think-queue`'s `InteractsWithTime`; update `workerman/workerman` v3.5.19→v3.5.34 for `#[\AllowDynamicProperties]` on `Worker`, which otherwise makes timer and workerman exit `FATAL` on `$workerId`; and update `topthink/think-template` v2.0.7→v2.0.10, whose `ob_implicit_flush(0)` raises a `TypeError` on PHP 8 and returns HTTP 500 for every rendered page including the homepage.
- [x] 4.13 Add a project-owned `Workerman\\Events\\Select` override casting the microsecond argument of `stream_select()` at the call boundary. Workerman 3.5 has no release that fixes this, no event extension is installed so `Select` is the active loop, and the scheduler deliberately keeps `$_selectTimeout` a float because `tick()` compares it against zero, so only the call site is converted. Without it every Workerman child crashes on `Implicit conversion from float ... to int loses precision` while the parent still reports `RUNNING`.
- [x] 4.14 Convert the 9 `ltrim()` token-header call sites across the `api`, `adminapi`, `kefuapi`, and `outapi` entrypoints to cast explicitly, since `Request::header()` returns null when the header is absent and PHP 8.1 deprecates passing null. Casting preserves the existing falsy-token behavior.

## 5. Verification

- [x] 5.1 Confirm the running PHP is on the 8.2 line with the required extensions and OPcache loaded and JIT disabled.
- [x] 5.2 Confirm application code reports zero parse failures and zero runtime deprecations, and that reachable vendor classes report zero runtime deprecations.
- [x] 5.3 Confirm PHP-FPM, queue, timer, and Workerman report `RUNNING` with Supervisor restart counts at zero, so a crash-restart loop cannot pass. This check earned its keep: the first cutover attempt reported all four `RUNNING` while Workerman child processes crashed and respawned 20734 times.
- [x] 5.4 Confirm homepage, admin entry, SPA fallback, representative public APIs, and `/notice` and `/msg` respond without 502.
- [x] 5.5 Compare PHP 7.4 baseline and PHP 8.2 candidate output directories generated from shared fixtures: require exact bytes, SHA256, and dimensions for every case except text PNG positions 1/5/9; require those three to keep dimensions and alpha identical with no more than one differing pixel and maximum RGB channel delta 1; require both animated GIF outputs to retain three frames; prove the tolerance rejects two changed pixels, RGB delta 2, and alpha changes; and require zero unexpected diagnostics.
- [x] 5.6 Confirm JWT encode/decode with neutral claims, including the `google/auth` path that pins `firebase/php-jwt ~5.0`.
- [x] 5.7 Confirm spreadsheet create, read, formatting, and image cases with neutral temporary data, and exercise order and product export.
- [x] 5.8 Confirm WeChat service construction, payment/refund and SMS construction, every upload provider including JDOSS's lazy `S3Client`, and service discovery complete under `--network none` without sending third-party transactions. Probe cases run as `www-data`, matching the FPM pool and Supervisor process user; running as root makes `var_www_rejects_write` fail because root bypasses the 0755 root-owned application tree.
- [x] 5.9 Confirm uploads remain writable by `www-data` and installer access stays blocked.
- [x] 5.10 Scan logs after exercised workflows for `Fatal error`, `TypeError`, `Deprecated`, and repeating worker restarts, excluding the recorded `Encoder.php:77` static-GIF warning.

## 6. Cutover

- [x] 6.1 Dump the database, snapshot upload count and bytes, and retag the current PHP 7.4 image as `tianli/crmeb-php:php74-rollback` before building the 8.2 image.
- [x] 6.2 Recreate application containers without `-v` and confirm the snapshot comparison shows database and uploads unchanged.
- [x] 6.3 Recreate the stack once more without deleting volumes and repeat process-health and data-preservation checks.
- [x] 6.4 Confirm the retagged PHP 7.4 image restores a healthy stack against the same database and uploads. Rollback serves PHP 7.4.33 with all four processes `RUNNING`, HTTP routes answering, and the snapshot unchanged. `/notice` and `/msg` return 400 rather than 502 on the 7.4 image, which means Workerman is listening and rejecting a plain HTTP request to a WebSocket endpoint; 502 would indicate an unreachable upstream.

## 7. Specification and handoff

- [x] 7.1 Run `openspec validate upgrade-php-82-runtime` and ensure CodeGraph is synchronized.
- [x] 7.2 Document the 8.2 build, verification, and PHP 7.4 rollback commands in the Docker help README, including the tag-swap rollback procedure and the `--no-build` requirement that stops a rollback from rebuilding over the `:acceptance` tag.
- [x] 7.3 Record the carried-forward static-GIF warning, the project-owned compatibility overrides that must be rechecked on any future dependency upgrade, and state that the 24 outstanding advisories across 9 packages, the two abandoned packages, and the absent test suite still block public production launch.
