## 1. Trustworthy baseline

- [x] 1.1 Install the unchanged lock into an empty vendor directory under PHP 7.4, then mount that immutable tree into the PHP 8.2 probe. Confirm installed versions and source references, `installed.json`, and the lock agree, and that the `crmeb/easypay`, `xin/container`, and `xin/helper` install paths exist. Do not use the stale `topthink/framework` source constant as a version check.
- [x] 1.2 Add a PHP 8.2 verification script that lints one file per process, continues past failures, reports every failing file, and exits nonzero if any failure occurred.
- [x] 1.3 Record the red baseline on the reinstalled tree: entrypoint PHP 8 rejection, `composer prohibits php 8.2 --locked` blockers, vendor parse failures with per-package counts, application parse failures, and application plus vendor runtime deprecations under `E_ALL`.
- [x] 1.4 Recompute the targeted lock delta with `--minimal-changes` on the clean tree and compare against the 10 changed, 3 added, 0 removed set in the design. Stop for review on any deviation.

## 2. PHP 8.2 runtime image

- [ ] 2.1 Add the `php:8.2-fpm-bookworm` Dockerfile installing the current extension set plus `sockets`, the Redis extension, and Supervisor. Add an isolated Composer stage, exclude the tracked host `vendor/` from the build context, copy the clean stage output into the final image, and verify the extension list against the PHP 7.4 image.
- [ ] 2.2 Add the PHP 8.2 OPcache INI with `opcache.jit=disable` and the existing production error, realpath-cache, and timestamp-validation settings.
- [ ] 2.3 Confirm `think service:discover` and `vendor:publish` complete during image build without database access, or move them to container start.
- [ ] 2.4 Re-confirm the disabled-function policy against the 8.2 image and that Workerman retains the `pcntl` support it needs to fork.

## 3. Dependency migration

- [x] 3.1 Constrain `composer.json` to `~8.2.0`, enable `platform-check`, and confirm no advisory-ignore, platform-ignore, or extension-ignore setting reaches the committed file.
- [x] 3.2 Apply the reviewed 10-update, 3-addition lock delta package by package with `--minimal-changes`, using the diagnostic advisory setting only locally and never committing it.
- [x] 3.3 Confirm `composer prohibits php 8.2 --locked` reports no package and `check-platform-reqs` passes on every entry with no bypass.
- [x] 3.4 Record baseline and resulting audits and confirm the advisory count and severity do not increase.

## 4. Compatibility repairs

- [ ] 4.1 Add project-owned replacements for `think-image` `gif/Decoder.php` and `gif/Encoder.php` converting the 29 curly-brace offset accesses, registered ahead of the package through `exclude-from-classmap` and a project classmap so `composer install` cannot overwrite them.
- [ ] 4.2 Add a project-owned replacement for `overtrue/wechat` `Support/Collection.php` with a valid static `__set_state()` reconstruction, PHP 8.2-compatible interface return types, and `__serialize()`/`__unserialize()` support, through the same mechanism.
- [ ] 4.3 Repair the 32 optional-before-required declarations across 26 files by removing the ineffective default, without reordering parameters, checking callers for each.
- [ ] 4.4 Replace `openssl_free_key()` in `crmeb/utils/Rsa.php`.
- [ ] 4.5 Allow the entrypoint to run on PHP 8.2, reject unvalidated release lines, and correct the supported-range message.

## 5. Verification

- [ ] 5.1 Confirm the running PHP is on the 8.2 line with the required extensions and OPcache loaded and JIT disabled.
- [ ] 5.2 Confirm application code reports zero parse failures and zero runtime deprecations, and that the only remaining vendor deprecations are the 8 recorded `think-orm` and `Pimple` return-type notices.
- [ ] 5.3 Confirm PHP-FPM, queue, timer, and Workerman report `RUNNING` with Supervisor restart counts at zero, so a crash-restart loop cannot pass.
- [ ] 5.4 Confirm homepage, admin entry, SPA fallback, representative public APIs, and `/notice` and `/msg` respond without 502.
- [ ] 5.5 Confirm image handling matches the PHP 7.4 baseline byte for byte: PNG thumb/water, static-GIF thumb/water, animated-GIF thumb/water with frames retained, and text watermark across positions with negative offsets and rotation.
- [ ] 5.6 Confirm JWT encode/decode with neutral claims, including the `google/auth` path that pins `firebase/php-jwt ~5.0`.
- [ ] 5.7 Confirm spreadsheet create, read, formatting, and image cases with neutral temporary data, and exercise order and product export.
- [ ] 5.8 Confirm WeChat service construction, upload provider construction, and service discovery complete without sending third-party transactions.
- [ ] 5.9 Confirm uploads remain writable by `www-data` and installer access stays blocked.
- [ ] 5.10 Scan logs after exercised workflows for `Fatal error`, `TypeError`, `Deprecated`, and repeating worker restarts, excluding the recorded `Encoder.php:77` static-GIF warning.

## 6. Cutover

- [ ] 6.1 Dump the database, snapshot upload count and bytes, and retag the current PHP 7.4 image as `tianli/crmeb-php:php74-rollback` before building the 8.2 image.
- [ ] 6.2 Recreate application containers without `-v` and confirm the snapshot comparison shows database and uploads unchanged.
- [ ] 6.3 Recreate the stack once more without deleting volumes and repeat process-health and data-preservation checks.
- [ ] 6.4 Confirm the retagged PHP 7.4 image restores a healthy stack against the same database and uploads.

## 7. Specification and handoff

- [ ] 7.1 Run `openspec validate upgrade-php-82-runtime` and ensure CodeGraph is synchronized.
- [ ] 7.2 Document the 8.2 build, verification, and PHP 7.4 rollback commands in the Docker help README.
- [ ] 7.3 Record the carried-forward known issues and state that outstanding advisories, the abandoned EasyWechat package, and the absent test suite still block public production launch.
