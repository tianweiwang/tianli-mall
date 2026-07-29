# PHP 8.2 Runtime Upgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the optimized acceptance runtime from PHP 7.4.33 to the PHP 8.2 line, preserving application behavior, the acceptance database, uploads, and a tested rollback path.

**Architecture:** Rebuild only the PHP application image from `php:8.2-fpm-bookworm`, with the extension set plus Supervisor installed explicitly. Install the reviewed minimal 13-package lock delta in an isolated Composer-on-PHP-8.2 build stage; the repository's tracked vendor tree never enters the image. Repair application declarations in place, and override two unpatchable vendor classes from project-owned code rather than editing `vendor/`. Nginx, MySQL, Redis, volumes, and data boundaries are unchanged.

**Tech Stack:** PHP 8.2 FPM on Debian bookworm, Composer 2 with platform checks, Zend OPcache with JIT disabled, Supervisor, Docker Compose, PowerShell verification.

**Tracked by:** `openspec/changes/upgrade-php-82-runtime`. Supersedes `docs/superpowers/specs/2026-07-29-php-82-compatibility-design.md`.

---

### Task 1: Establish a trustworthy baseline

The prior baseline is not usable. `crmeb/easypay`, `xin/container`, and `xin/helper` appear in the lock and `installed.json` with install paths absent from disk and from the running container, and an unrecorded `phpoffice/phpexcel` directory remains. `topthink/framework v6.1.2` itself ships `const VERSION = '6.1.1'`, including after a clean install, so that constant is not an integrity signal. Every dependency measurement must be retaken after a clean isolated install.

**Files:**
- Create: `help/docker/verify-php82.ps1`

- [ ] **Step 1: Write a failing baseline-integrity check**

Add a verifier mode that compares lock package names, versions, and source references with `installed.json`, resolves every `install-path`, and fails on an unexpected package directory. Run it against the current tracked tree.

Expected: FAIL for the three missing install paths and the unrecorded `phpoffice/phpexcel` directory.

- [ ] **Step 2: Rebuild the unchanged baseline tree in isolation**

Install the current lock into an empty vendor directory under PHP 7.4. If an actual PHP 7.4 Composer environment is unavailable, use `--ignore-platform-req=php` only inside the disposable baseline container. Do not modify `composer.json` or `composer.lock`. Re-run the integrity check against the clean tree.

Expected: PASS; all 93 metadata entries have install paths, no unrecorded package directory exists, and `topthink/framework` is verified by Composer metadata/source reference rather than `App::VERSION`.

- [ ] **Step 3: Write the lint harness so it cannot hide failures**

`php -l` exits 255 on a fatal parse error, which makes `xargs` abort the entire batch and skip every remaining file. That defect recorded a 25-failure tree as having one failure. Lint one file per process and continue past failures:

```bash
root=${1:-/var/www}
failures=0
while IFS= read -r -d '' file; do
  if ! output=$(php -l "$file" 2>&1); then
    printf '%s\n' "$output"
    failures=$((failures + 1))
  fi
done < <(find "$root" -type f -name '*.php' -print0)
test "$failures" -eq 0
```

Run the harness first against a neutral temporary fixture containing two invalid PHP files. Expected: both paths are printed and the command exits nonzero. Never use `find … -print0 | xargs -0 php -l` for this purpose.

- [ ] **Step 4: Add a runtime deprecation probe**

Lint cannot see dynamic properties, `Serializable`, or return-type deprecations. Load every autoloadable class under `E_ALL` with a `set_error_handler` that records diagnostics, deriving the class list from `vendor/composer/autoload_classmap.php` plus namespace and class declarations under `app/` and `crmeb/`, and reflect each class's methods and properties.

Expected on the current tree: 1042 classes loaded, 0 load failures.

- [ ] **Step 5: Record the red baseline**

Capture, on the reinstalled tree: entrypoint PHP 8 rejection, `composer prohibits php 8.2 --locked` blockers, vendor parse failures with per-package counts, application parse failures, and application plus vendor runtime deprecations.

Expected from the pre-reinstall measurement, to be re-confirmed: 10 dependency blockers; 32 optional-before-required declarations across 26 files producing 24 distinct application deprecations; 8 vendor deprecations in `think-orm` and `Pimple`; 0 application parse failures. Vendor parse failures were 25 across `phpoffice/phpexcel` (19), `topthink/think-image` (2), `overtrue/wechat` (1), `doctrine/cache` (1), `nesbot/carbon` (1), and `symfony/var-dumper` tests (1). The 19 `phpexcel` failures should disappear after reinstall, because that package is in neither the lock nor `installed.json`, is referenced by no autoload map, and appears only in comments in `crmeb/services/SpreadsheetExcelService.php`.

- [ ] **Step 6: Recompute the lock delta and compare**

Recompute the targeted update with `--with-all-dependencies --minimal-changes` on the clean tree and diff it against the 10-update, 3-addition, 0-removal delta recorded in the design. Any deviation stops the change for review.

### Task 2: Build the PHP 8.2 runtime image

The current vendor image supplies the extensions **and** Supervisor at `/usr/bin/supervisord`. The official image supplies neither, so the existing `CMD` would fail at container start unless Supervisor is installed explicitly.

**Files:**
- Modify: `docker/php/Dockerfile`
- Modify: `docker/php/opcache.ini`
- Modify: `.dockerignore`

- [ ] **Step 1: Derive from the official image and install what the base lacks**

Install the compiled extension set, the Redis extension via PECL, and Supervisor from apt. `sockets` is added for Workerman. Copy the Composer binary from the pinned Composer 2 image into the PHP 8.2 base stage. Keep the existing entrypoint, Supervisor config, and healthcheck unchanged.

```dockerfile
FROM php:8.2-fpm-bookworm
COPY --from=composer:2.10 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends \
      supervisor libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
       bcmath gd mysqli pcntl pdo_mysql simplexml sockets zip opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
```

- [ ] **Step 2: Install dependencies in an isolated image stage**

Add `crmeb/vendor/` to `.dockerignore`. Create a `vendor` stage from the PHP 8.2 base. Copy `composer.json`, `composer.lock`, and the three project-owned override paths first so dependency downloads remain cacheable, and run:

```dockerfile
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress \
      --no-plugins --no-scripts
```

Copy the remaining application code into the vendor stage, run `composer dump-autoload --no-dev --classmap-authoritative --no-plugins --no-scripts`, then run `php think service:discover` and `php think vendor:publish` explicitly after all code is present. Copy the complete `/var/www` tree from this stage into the final runtime stage. Confirm `docker history` and an image-side package-integrity check show no tracked host vendor content.

- [ ] **Step 3: Verify the extension set against the PHP 7.4 image**

The 7.4 image loads: `bcmath ctype curl date dom fileinfo filter ftp gd hash iconv json libxml mbstring mysqli mysqlnd openssl pcntl pcre PDO pdo_mysql pdo_sqlite Phar posix readline redis Reflection session SimpleXML sodium SPL sqlite3 standard tokenizer xml xmlreader xmlwriter Zend OPcache zip zlib`.

Compare `php -m` between both images and justify any absence.

- [ ] **Step 4: Disable JIT explicitly**

`opcache.jit` defaults to a tracing JIT on the PHP 8 line. Queue, timer, and Workerman are long-lived processes where tracing JIT has known stability problems, and JIT gains nothing for this workload. Add to the INI, keeping all existing settings:

```ini
opcache.jit=disable
opcache.jit_buffer_size=0
```

- [ ] **Step 5: Confirm build-time autoload scripts need no database**

`post-autoload-dump` runs `think service:discover` and `vendor:publish`, but Composer install happens in a build stage with no MySQL available. Confirm both are static; if either touches the database, move them to container start.

- [ ] **Step 6: Re-confirm the disabled-function policy**

`README.md` instructs disabling `proc_open` and the `pcntl_*` family, but Workerman needs `pcntl` to fork. Confirm which of these are actually disabled in the 8.2 image and that queue, timer, and Workerman still start.

### Task 3: Apply the dependency migration

**Files:**
- Modify: `crmeb/composer.json`
- Modify: `crmeb/composer.lock`

- [ ] **Step 1: Constrain the platform and enable checking**

Set `require.php` to `~8.2.0` and `config.platform-check` to `true`, replacing the current `false`. The committed file must contain no advisory-ignore, platform-ignore, or extension-ignore setting.

- [ ] **Step 2: Compute the minimal lock with a command-only advisory bypass**

Guzzle 6.5.5 is advisory-blocked by current Composer policy. Use Composer 2.10's command-only `--no-blocking` option so no diagnostic setting is written to `composer.json`. Target the ten blockers with `--with-all-dependencies --minimal-changes --no-install --no-plugins --no-scripts --no-audit`:

```
markbaker/complex markbaker/matrix paragonie/random_compat
phpoffice/phpspreadsheet spatie/macroable symfony/process
symfony/psr-http-message-bridge symfony/translation
symfony/translation-contracts symfony/var-dumper
```

- [ ] **Step 3: Review the minimal delta package by package**

Expected: 10 changed and 3 added (`composer/pcre` 3.4.0, `ezyang/htmlpurifier` v4.19.0, `symfony/polyfill-php80` v1.37.0), with no removals. Full changed list is in the design.

Note that `symfony/psr-http-message-bridge` moves **down**, v1.2.0 to v1.0.2, because v1.2.0 requires `http-foundation ^3.4||^4.0` while EasyWechat requires `~2.6|~2.7|~2.8|~3.0`; v1.0.2 satisfies both. `paragonie/random_compat` moves to v9.99.100 rather than being removed. `overtrue/wechat 3.3.33`, `doctrine/cache v1.4.4`, ThinkPHP, Think Queue, JWT, Guzzle, and unrelated transitive packages stay at their locked versions.

- [ ] **Step 4: Prove the platform gates pass with no bypass**

Run:

```
composer prohibits php 8.2 --locked
composer check-platform-reqs
```

Expected: `There is no installed package depending on "php" in versions not matching 8.2`, and every `check-platform-reqs` entry reports `success`.

- [ ] **Step 5: Compare audits**

Record baseline and resulting `composer audit` output. The advisory count and severity must not increase. The 62 outstanding advisories across 12 packages remain a launch blocker tracked separately.

### Task 4: Repair application code

**Files:**
- Modify: 26 files carrying optional-before-required declarations
- Modify: `crmeb/crmeb/utils/Rsa.php`
- Modify: `crmeb/public/index.php`

- [ ] **Step 1: Remove ineffective defaults without reordering**

PHP 8 already treats a parameter after a defaulted one as required, so the default is dead. Remove it; do not reorder, because positional callers would change meaning.

Fourteen of the 32 are `Dao` methods shaped `(string $field = '*', int $page, int $limit)`. Verified example: `app/dao/user/UserDao.php:108` `getAgentUserList`, whose only caller (`app/services/user/UserServices.php:256`) passes `$field` explicitly, so removal is safe.

```php
// before
public function getAgentUserList(array $where, string $field = '*', int $page, int $limit)
// after
public function getAgentUserList(array $where, string $field, int $page, int $limit)
```

Check callers for each site individually. Where an inherited signature or a real caller relies on omission, treat the declaration and its callers as one change. No unrelated cleanup or reformatting.

- [ ] **Step 2: Replace the deprecated OpenSSL call**

`crmeb/crmeb/utils/Rsa.php:120` calls `openssl_free_key($res)`, a no-op deprecated from PHP 8.0. Remove the call; `OpenSSLAsymmetricKey` is released by the garbage collector. Keep the surrounding return semantics unchanged.

- [ ] **Step 3: Correct the entrypoint version gate**

`crmeb/public/index.php` currently rejects PHP 8 outright:

```php
if (phpversion() >= '8.0.0') {
    exit('您的php版本太高，不能安装本软件，兼容php版本7.1~7.4，谢谢！');
}
```

Replace both bounds so PHP 8.2 is accepted and unvalidated release lines are refused, and correct the message to state the real supported range. Use `PHP_VERSION_ID` rather than string comparison, since `'8.10.0' >= '8.2.0'` is false as a string compare.

### Task 5: Override the two unpatchable vendor classes

`vendor/` is never edited. Both of these are compile-time failures on reachable paths.

**Files:**
- Create: `crmeb/crmeb/services/image/gif/Decoder.php`
- Create: `crmeb/crmeb/services/image/gif/Encoder.php`
- Create: `crmeb/crmeb/services/wechat/Collection.php`
- Modify: `crmeb/composer.json`

- [ ] **Step 1: Convert the think-image GIF classes**

`topthink/think-image` v1.0.7 uses curly-brace offset access, removed in PHP 8.0, at `gif/Decoder.php:165` and 28 sites in `gif/Encoder.php`. `crmeb/services/upload/storage/Local.php:263,341,365` and `crmeb/utils/DownloadImage.php:111` call `Image::open()` on local uploads, and `Image.php:78` constructs the GIF decoder whenever the type is `gif`. Because this is a compile-time error, the `catch (\Throwable $e)` blocks around those calls never run: a GIF upload returns HTTP 500 with nothing logged.

The conversion is mechanical, `$expr{offset}` → `$expr[offset]`, 29 replacements total:

```php
// before
$this->GIF_buffer[] = ord($this->GIF_stream{$this->GIF_bfseek++});
// after
$this->GIF_buffer[] = ord($this->GIF_stream[$this->GIF_bfseek++]);
```

Keep the `think\image\gif` namespace and class names so `Image.php` resolves to these copies. Change nothing else, including the pre-existing `Encoder.php:77` bug.

Do **not** substitute `intervention/image` 2.7.2. It is already present via `fastknife/ajcaptcha` and is PHP 8.2 compatible, but its GD decoder reads only the first GIF frame, so it would silently flatten every animated GIF.

- [ ] **Step 2: Write the failing WeChat collection compatibility probe**

`vendor/overtrue/wechat/src/Support/Collection.php:354` declares `public function __set_state()` with no parameters. PHP 8 requires exactly one, which is a compile-time failure. The class also implements the `Serializable` interface deprecated from PHP 8.1.

The probe loads the project-owned class under `E_ALL`, round-trips a neutral collection through `serialize()`/`unserialize()`, `__serialize()`/`__unserialize()`, and `var_export()`/`eval()`, and exercises `ArrayAccess`, `count()`, iteration, and JSON serialization. Expected before the override: compile failure. Expected after only adding one parameter: `Method ...::__set_state() must be static`. Expected after making it static without changing the body: `Using $this when not in object context`.

- [ ] **Step 3: Implement the complete WeChat collection contract**

```php
// before
public function __set_state()

// after
public static function __set_state(array $state)
{
    $collection = new static();
    $collection->items = $state['items'] ?? [];
    return $collection;
}
```

Add PHP 8.2-compatible interface return types to `offsetExists`, `offsetGet`, `offsetSet`, `offsetUnset`, `count`, `getIterator`, and `jsonSerialize`. Add `__serialize()` returning the item payload and `__unserialize(array $data): void` restoring it, while retaining the package's legacy `serialize()` and `unserialize()` methods. Keep the `EasyWeChat\Support` namespace and all unrelated package behavior unchanged. The compatibility probe must pass with zero diagnostics from this class.

- [ ] **Step 4: Register the overrides ahead of the packages**

Exclude the packaged files from the classmap and add the project copies, so `composer install` cannot overwrite them and they stay in version control:

```json
"autoload": {
    "exclude-from-classmap": [
        "vendor/topthink/think-image/src/image/gif/Decoder.php",
        "vendor/topthink/think-image/src/image/gif/Encoder.php",
        "vendor/overtrue/wechat/src/Support/Collection.php"
    ],
    "classmap": [
        "crmeb/services/image/gif/",
        "crmeb/services/wechat/Collection.php"
    ]
}
```

- [ ] **Step 5: Prove the overrides take effect and vendor is untouched**

After `composer dump-autoload --classmap-authoritative`, confirm the resolved file for `think\image\gif\Encoder` and `EasyWeChat\Support\Collection` is the project copy, and that `git status` shows no modification under `vendor/`.

### Task 6: Verify compatibility

**Files:**
- Modify: `help/docker/verify-php82.ps1`

- [ ] **Step 1: Assert the runtime**

PHP is on the 8.2 line; the required extensions are loaded; OPcache is enabled; JIT is disabled.

- [ ] **Step 2: Assert code cleanliness with the corrected harness**

Application code and project-owned compatibility classes report zero parse failures and zero runtime deprecations. The only remaining vendor deprecations are the 8 recorded return-type notices: `think\Model::jsonSerialize`, `offsetGet`, `offsetSet`, `offsetUnset` in `think-orm`, and the four matching `Pimple\Container` offset methods. Both need `#[\ReturnTypeWillChange]` in vendor code and are out of scope.

- [ ] **Step 3: Assert processes are genuinely stable**

PHP-FPM, queue, timer, and Workerman report `RUNNING` **and** Supervisor restart counts are zero. `startsecs=3` means a crash-restart loop still reports `RUNNING`, so state alone is not sufficient evidence.

- [ ] **Step 4: Assert HTTP and WebSocket behavior**

Homepage, admin entry, SPA fallback, and representative public APIs respond successfully; `/notice` and `/msg` do not return 502.

- [ ] **Step 5: Assert image handling is byte-identical to the 7.4 baseline**

Compare against PHP 7.4 with the unpatched package. Verified expected values, from a 120x80 source with a DejaVu font, are:

| Case | Bytes |
| --- | --- |
| PNG thumb / water | 130 / 205 |
| static GIF thumb / water | 93 / 182 |
| animated GIF thumb / water | 429 / 717, 3 frames retained |
| text watermark, positions 1/5/9 on PNG | 2041 / 2025 / 1860 |
| text watermark, positions 1/5/9 on GIF | 635 / 630 / 613 |

The text cases must include a negative offset and a rotation, matching the call shape at `crmeb/services/upload/storage/Local.php:373`.

- [ ] **Step 6: Assert dependency-sensitive integrations**

JWT encode/decode with neutral claims, including the `google/auth` path that pins `firebase/php-jwt ~5.0`. Spreadsheet create, read, formatting, and image cases with neutral temporary data, plus order and product export, since PhpSpreadsheet moves 17 minor versions and backs `app/adminapi/controller/v1/order/StoreOrder.php` and `.../product/StoreProduct.php`. WeChat service construction, upload provider construction, and service discovery, without sending third-party transactions.

- [ ] **Step 7: Assert filesystem and installer posture**

Uploads remain writable by `www-data`; installer access stays blocked.

- [ ] **Step 8: Scan logs**

No `Fatal error`, `TypeError`, unexpected `Deprecated`, or repeating worker restart. Exclude exactly one recorded site: `think-image` `gif/Encoder.php:77` emits `Undefined array key 0` for static GIFs. It is present on PHP 7.4 as a Notice, is unrelated to this migration, and does not change output bytes. Repairing it means changing frame-array logic, which is a functional fix, not a compatibility one.

### Task 7: Cut over and confirm rollback

**Files:**
- Modify: `help/docker/README.md`
- Modify: `openspec/changes/upgrade-php-82-runtime/tasks.md`

- [ ] **Step 1: Snapshot state and secure the rollback tag**

Dump the database and record upload count and bytes. Then retag the current PHP 7.4 image **before** building the 8.2 image, because a build would otherwise overwrite the shared `acceptance` tag:

```powershell
docker tag tianli/crmeb-php:acceptance tianli/crmeb-php:php74-rollback
```

Compare data against this snapshot, not against hard-coded literals. The previously recorded 25,210 language rows, 174 uploads, and 21,664,281 bytes drift as soon as anyone uses the environment, and uploads are a live bind mount.

- [ ] **Step 2: Build and verify statically before touching containers**

Build the 8.2 image and run the compatibility checks against it before recreating anything.

- [ ] **Step 3: Recreate application containers**

Recreate without `-v`, preserving MySQL data, Redis state, the runtime volume, and uploads. No migration command or schema mutation runs.

- [ ] **Step 4: Verify, then verify again after recreation**

Run the full verification, then `down` and `up -d` again without `-v` and repeat process-health and data-preservation checks.

- [ ] **Step 5: Prove the rollback works**

Restore `tianli/crmeb-php:php74-rollback` and confirm a healthy stack against the same database and uploads. No database rollback is needed, since this change performs no schema or data migration; the dump is a defensive artifact only.

- [ ] **Step 6: Document and hand off**

```powershell
openspec validate upgrade-php-82-runtime
codegraph sync
```

The README must document the 8.2 build, verification, and PHP 7.4 rollback commands, never with `-v`, and must state that the carried-forward known issues still block public production launch: 8 vendor deprecations, the static-GIF warning, 62 dependency advisories across 12 packages, the abandoned EasyWechat package, and the absent first-party test suite.
