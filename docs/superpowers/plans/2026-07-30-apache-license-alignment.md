# Apache-2.0 License Alignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the distributed CRMEB application consistently Apache-2.0, remove commercial-authorization and forced-branding behavior, and keep official remote upgrades disabled and network-free by default without changing commerce or demo data.

**Architecture:** Keep ordinary application authentication, local diagnostics, site copyright configuration, and local cross-version upgrade code intact. Remove the separate vendor-commercial-authorization chain, put every official remote-upgrade HTTP call behind one lazy default-off gate, and apply narrowly identified installer/existing-database cleanup with repeatable verification.

**Tech Stack:** ThinkPHP 6 / PHP 8.2, Vue 2 + Element UI, uni-app, MySQL 8, Redis, Docker Compose, PowerShell verification scripts, OpenSpec 1.4.

---

## File Map

- `openspec/changes/align-apache-license-and-disable-vendor-auth/`: the only active OpenSpec change for this work.
- `help/license-alignment/`: deterministic header replacement, static compliance checks, focused probes, and tests.
- `crmeb/config/upgrade.php`: default-off flag and HTTPS official endpoint configuration.
- `crmeb/app/services/system/UpgradeServices.php`: network-free construction and lazy remote gate; local backup/rollback methods stay here.
- `crmeb/app/adminapi/controller/UpgradeController.php` and `crmeb/app/adminapi/route/system.php`: local capability endpoint.
- `crmeb/app/adminapi/controller/Common.php`, `crmeb/app/adminapi/route/common.php`, and `crmeb/app/services/system/SystemAuthServices.php`: remove commercial authorization while retaining `/copyright`.
- `template/admin/src/pages/system/info/index.vue`: retained local diagnostics and copyright editor, renamed from the former authorization page.
- `template/admin/src/pages/system/onlineUpgrade/index.vue`: capability-first default-disabled state.
- `template/admin/src/components/copyright/index.vue` and listed login/customer/storefront pages: render only configured copyright values.
- `template/uni-app/`, `crmeb/public/index.html`, `crmeb/public/static/`, and `crmeb/public/statics/mp_view/`: remove the unused support-image fallback in source, then rebuild and synchronize the distributed H5 and mini-program without hand-editing compiled JavaScript.
- `crmeb/public/install/`: Apache installer text, no completion telemetry, and clean fresh-install metadata.
- `crmeb/crmeb/command/LicenseAlignmentCleanup.php`: prefix-aware idempotent existing-environment cleanup, including the cached remote token.
- `crmeb/public/admin/`: rebuilt and synchronized management assets; minified files are never edited by hand.

## Guardrails Applied to Every Task

- Record the initial dirty-file list with `git status --short` and never stage `.env`, `.constant`, `.codex/`, the existing PHP 8.2 compatibility work, or unrelated OpenSpec changes.
- Do not modify `vendor/`, `composer.lock`, product/order/user/upload rows, homepage decoration, or demo data.
- Never hand-edit generated files under `crmeb/public/admin/`, `crmeb/public/static/`, or `crmeb/public/statics/mp_view/`; update them only by a successful build from their tracked sources through a verified staging directory.
- Use the exact environment contract `Env::get('upgrade.remote_enabled', false)` and deployment variable `UPGRADE_REMOTE_ENABLED=false`.
- Keep CRMEB copyright and author attribution and all third-party licenses.
- For the three already-dirty header files (`crmeb/app/common.php`, `crmeb/app/dao/product/product/StoreProductDao.php`, `crmeb/config/database.php`), stage only the one-line header replacement with an index patch; do not stage their pre-existing edits.

### Task 1: Correct and Validate the OpenSpec Contract

**Files:**
- Create: `docs/superpowers/plans/2026-07-30-apache-license-alignment.md`
- Delete: `docs/superpowers/specs/2026-07-30-apache-commercial-distribution-design.md`
- Delete: `openspec/changes/align-apache-commercial-distribution/.openspec.yaml`
- Delete: `openspec/changes/align-apache-commercial-distribution/proposal.md`
- Delete: `openspec/changes/align-apache-commercial-distribution/design.md`
- Delete: `openspec/changes/align-apache-commercial-distribution/tasks.md`
- Delete: `openspec/changes/align-apache-commercial-distribution/specs/commercial-distribution-compliance/spec.md`
- Create: `openspec/changes/align-apache-license-and-disable-vendor-auth/proposal.md`
- Create: `openspec/changes/align-apache-license-and-disable-vendor-auth/design.md`
- Create: `openspec/changes/align-apache-license-and-disable-vendor-auth/tasks.md`
- Create: `openspec/changes/align-apache-license-and-disable-vendor-auth/specs/apache-license-alignment/spec.md`

- [ ] **Step 1: Replace the concurrently-created divergent change with the confirmed change name**

The proposal must declare exactly these capability boundaries:

```markdown
## What Changes

- Replace project-owned restrictive license statements and nested proprietary licenses with Apache-2.0 while retaining copyright and author attribution.
- Remove commercial authorization queries, applications, purchases, automatic dashboard checks, forced CRMEB branding fallbacks, and installer completion telemetry.
- Keep local copyright text/image configuration and its existing `/copyright` response contract.
- Disable official remote upgrades by default with `UPGRADE_REMOTE_ENABLED=false`; when disabled, no token lookup or CRMEB network request occurs.
- Keep local cross-version upgrades, backups, and rollback independent from official remote authorization.
- Remove only exact authorization metadata from fresh-install and existing-environment data; commerce and demo data remain unchanged.
```

- [ ] **Step 2: Write the normative scenarios**

The spec delta must include these scenario outcomes verbatim:

```markdown
#### Scenario: Official remote upgrade is disabled
- **WHEN** `UPGRADE_REMOTE_ENABLED` is absent or false
- **THEN** `GET /system/upgrade/capability` SHALL return business `status=200` with `data.remote_enabled=false`
- **THEN** constructing the upgrade service SHALL NOT read `upgrade_auth_token`
- **THEN** remote upgrade methods SHALL return business `status=400` with `官方在线升级服务未启用`
- **THEN** no request to a CRMEB domain SHALL occur

#### Scenario: Local upgrade remains available
- **WHEN** official remote upgrade is disabled
- **THEN** local cross-version inspection, SQL preview, backup, execution, progress, and rollback SHALL remain available
- **THEN** those operations SHALL NOT require remote login

#### Scenario: Commerce data is preserved
- **WHEN** cleanup is applied to an existing environment
- **THEN** only the named authorization configuration, route metadata, authorization-only translations, menu metadata, file-integrity inventory, and cached remote token SHALL change
- **THEN** products, orders, users, uploads, homepage decoration, and demo data SHALL remain byte-for-byte or row-digest equivalent
```

- [ ] **Step 3: Validate the corrected OpenSpec**

Run:

```powershell
openspec validate align-apache-license-and-disable-vendor-auth
```

Expected: `Change 'align-apache-license-and-disable-vendor-auth' is valid` and exit code `0`.

- [ ] **Step 4: Commit only the corrected specification files**

```powershell
git add -- docs/superpowers/plans/2026-07-30-apache-license-alignment.md docs/superpowers/specs/2026-07-30-apache-commercial-distribution-design.md openspec/changes/align-apache-commercial-distribution openspec/changes/align-apache-license-and-disable-vendor-auth
git diff --cached --check
git commit -m "spec: align Apache license cleanup contract"
```

Expected: the commit records this implementation plan, deletes the divergent change introduced by `c791dab5`, adds the confirmed change, and contains no runtime code.

### Task 2: Add a Failing Compliance Baseline

**Files:**
- Create: `help/license-alignment/LicenseAlignment.psm1`
- Create: `help/license-alignment/tests/LicenseAlignment.Tests.ps1`
- Create: `help/license-alignment/verify-license-alignment.ps1`
- Create: `help/license-alignment/probes/upgrade-remote-policy.php`

- [ ] **Step 1: Write source-scope and byte-preservation tests**

The test runner follows the existing self-contained PowerShell test style and uses neutral fixtures:

```powershell
$tracked = Get-ProjectOwnedTrackedFiles -RepositoryRoot $repositoryRoot
Assert-True ($tracked -notcontains 'crmeb/vendor/example.php') 'vendor file entered project-owned scope'
Assert-True ($tracked -notcontains 'docs/superpowers/specs/history.md') 'historical specification entered runtime scope'

$fixture = Join-Path $temporaryRoot 'sample.php'
[IO.File]::WriteAllBytes($fixture, [Text.UTF8Encoding]::new($true).GetBytes("line1`r`nLicensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权`r`nline3`r`n"))
Replace-RestrictiveLicenseHeader -LiteralPath $fixture
$bytes = [IO.File]::ReadAllBytes($fixture)
Assert-True ($bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) 'UTF-8 BOM changed'
Assert-True ([Text.Encoding]::UTF8.GetString($bytes).Contains("line1`r`nLicensed under the Apache License, Version 2.0`r`nline3")) 'content or CRLF changed unexpectedly'
```

- [ ] **Step 2: Implement the scanner without modifying files**

`Get-ProjectOwnedTrackedFiles` must enumerate `git ls-files -z` and exclude only:

```powershell
$excludedPrefixes = @(
    'crmeb/vendor/',
    'template/admin/node_modules/',
    'template/admin/dist/',
    'crmeb/public/admin/',
    'crmeb/public/static/',
    'crmeb/public/statics/',
    '.codegraph/',
    '.codex/',
    '.worktrees/',
    'docs/superpowers/specs/',
    'docs/superpowers/plans/',
    'openspec/'
)
```

The final verifier must report nonzero exit status for any of these runtime/fresh-install findings:

```powershell
$forbiddenRuntimeText = @(
    'Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权',
    'authorize.crmeb.net',
    'auth_cert_query',
    'auth_apply',
    'cert_crmeb',
    'CRMEB提供技术支持'
)
```

The header scanner excludes generated output, but the final verifier must explicitly scan the active published trees `crmeb/public/admin/`, `crmeb/public/static/`, and `crmeb/public/statics/mp_view/` for authorization and forced-branding strings. It must also fail while any of `template/uni-app/static/images/support.png`, `crmeb/public/static/images/support.png`, or `crmeb/public/statics/mp_view/static/images/support.png` exists. Separately verify that copyright/author lines remain present and that root, `crmeb/LICENSE.txt`, and `help/resource/LICENSE.txt` contain the Apache-2.0 legal text.

- [ ] **Step 3: Add a network-free upgrade probe**

The PHP probe creates a temporary ThinkPHP application root with no `.version`, configures `upgrade.remote_enabled=false`, instantiates `UpgradeServices`, and asserts:

```php
$service = new UpgradeServices(new UpgradeLogDao());
$checks['constructor_without_version_file'] = true;
$checks['capability_default_false'] = $service->isRemoteEnabled() === false;
try {
    $service->getUpgradeList();
    $checks['remote_call_rejected'] = false;
} catch (AdminException $exception) {
    $checks['remote_call_rejected'] = $exception->getMessage() === '官方在线升级服务未启用';
}
```

The probe also invokes HTTPS validation by reflection with one `https://upgrade.example.test/api` value and one `http://upgrade.example.test/api` value; the latter must be rejected before any HTTP method runs.

- [ ] **Step 4: Run the baseline and record the expected failures**

Build the current image, then run the probe with networking disabled so the failing baseline cannot contact any external service:

```powershell
pwsh -NoProfile -File help/license-alignment/tests/LicenseAlignment.Tests.ps1
pwsh -NoProfile -File help/license-alignment/verify-license-alignment.ps1
docker compose -f help/docker/docker-compose.optimized.yml build phpfpm
$upgradeProbe = (Resolve-Path 'help/license-alignment/probes/upgrade-remote-policy.php').Path
docker run --rm --network none --mount "type=bind,source=$upgradeProbe,target=/probe/upgrade-remote-policy.php,readonly" tianli/crmeb-php:acceptance php -d open_basedir= /probe/upgrade-remote-policy.php
```

Expected before implementation:

- unit fixtures pass;
- compliance verifier fails with `1242` project-owned restrictive header files plus authorization/branding/published-asset findings;
- upgrade probe fails because construction reads version/auth state and remote calls lack the disabled gate.

- [ ] **Step 5: Commit only the verification harness**

```powershell
git add -- help/license-alignment
git diff --cached --check
git commit -m "test: add Apache alignment compliance baseline"
```

### Task 3: Align License Text and Installer Behavior

**Files:**
- Modify: `crmeb/LICENSE.txt`
- Modify: `help/resource/LICENSE.txt`
- Modify: `crmeb/public/install/templates/step1.php`
- Modify: `crmeb/public/install/templates/step5.php`
- Modify: the 1242 tracked project-owned source files returned by `Get-ProjectOwnedTrackedFiles`

- [ ] **Step 1: Run the exact byte-level header replacement**

The implementation must replace only these UTF-8 byte sequences and preserve every other byte:

```powershell
$old = [Text.Encoding]::UTF8.GetBytes('Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权')
$new = [Text.Encoding]::UTF8.GetBytes('Licensed under the Apache License, Version 2.0')
```

Run in check mode first, expect exactly `1242` tracked project-owned matches, then run write mode and expect exactly `1242` changed files. Abort if any file has zero or multiple matches relative to the recorded set.

- [ ] **Step 2: Put the full Apache-2.0 license in both independently distributed trees**

`crmeb/LICENSE.txt` and `help/resource/LICENSE.txt` must contain the complete text from root `LICENSE`, not only a pointer. Verify:

```powershell
(Get-FileHash LICENSE -Algorithm SHA256).Hash -eq (Get-FileHash crmeb/LICENSE.txt -Algorithm SHA256).Hash
(Get-FileHash LICENSE -Algorithm SHA256).Hash -eq (Get-FileHash help/resource/LICENSE.txt -Algorithm SHA256).Hash
```

Expected: both expressions are `True`.

- [ ] **Step 3: Replace the installer EULA and remove completion telemetry**

Keep the step-one checkbox but replace the proprietary agreement body with a concise Apache-2.0 notice that states commercial use/modification/distribution rights, attribution/change-notice duties, warranty disclaimer, trademark exclusion, and the bundled `LICENSE` location.

In `step5.php`, delete `$uri`, `$root`, and `$admin` if unused and delete the entire `$.ajax` block. Keep only local navigation and render frontend/backend links from the current request scheme and host.

- [ ] **Step 4: Verify license and installer checks**

Run:

```powershell
pwsh -NoProfile -File help/license-alignment/tests/LicenseAlignment.Tests.ps1
php -l crmeb/public/install/templates/step1.php
php -l crmeb/public/install/templates/step5.php
git diff --numstat -- crmeb template help | Sort-Object
```

Expected: tests pass; both PHP files report no syntax errors; each mechanically changed source file shows one removed line and one added line apart from the explicitly edited installer/license files.

- [ ] **Step 5: Stage without capturing pre-existing dirty hunks and commit**

Stage clean header files normally. For the three pre-dirty files, apply an index-only zero-context patch containing only:

```diff
-// | Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权
+// | Licensed under the Apache License, Version 2.0
```

Then run:

```powershell
git diff --cached --check
git diff --cached -- crmeb/app/common.php crmeb/app/dao/product/product/StoreProductDao.php crmeb/config/database.php
git commit -m "chore: align project license notices"
```

Expected: the cached diff for each pre-dirty file contains only the license line; user PHP 8.2/runtime edits remain unstaged.

### Task 4: Remove Commercial Authorization and Legacy Telemetry Code

**Files:**
- Modify: `crmeb/app/adminapi/controller/Common.php`
- Modify: `crmeb/app/adminapi/route/common.php`
- Delete: `crmeb/app/services/system/SystemAuthServices.php`
- Delete: `crmeb/app/adminapi/controller/v1/system/SystemUpgradeClient.php`
- Delete: `crmeb/crmeb/services/UpgradeService.php`
- Delete: `crmeb/crmeb/services/UpgradeOpenApiService.php`
- Modify: `template/admin/src/pages/index/index.vue`
- Modify: `template/admin/src/api/system.js`

- [ ] **Step 1: Make the focused authorization test fail on current code**

The verifier must assert all three common routes and the two controller methods are absent, the four deleted classes do not exist, and the dashboard does not import or invoke `auth()`.

Run:

```powershell
pwsh -NoProfile -File help/license-alignment/verify-license-alignment.ps1 -Area Authorization
```

Expected before changes: fail with exact paths and symbols.

- [ ] **Step 2: Remove backend commercial authorization**

Delete `Common::auth()`, `Common::auth_apply()`, and the now-unused imports `SystemAuthServices`, `UpgradeServices`, and `HttpService`. Preserve `SystemConfigServices` because `/copyright` still uses it.

Delete these exact route declarations:

```php
Route::get('check_auth', 'Common/auth');
Route::post('auth_apply', 'Common/auth_apply');
Route::get('auth', 'Common/auth');
```

Do not touch `AuthController`, token middleware, role middleware, WeChat OAuth, or API user authentication.

- [ ] **Step 3: Remove the unreferenced legacy upgrade/telemetry implementation**

Delete the three legacy files only after repeating the caller search:

```powershell
rg -n "SystemUpgradeClient|UpgradeOpenApiService|UpgradeService" crmeb/app crmeb/crmeb -g '*.php'
```

Expected before deletion: `SystemUpgradeClient` calls `UpgradeService`; `UpgradeOpenApiService` has no caller; no route targets `SystemUpgradeClient`. Expected after deletion: only historical design/spec references, which the runtime verifier excludes.

- [ ] **Step 4: Remove the dashboard automatic authorization request and APIs**

Delete the `auth` import, `mounted()` call, and `getAuth()` method from `template/admin/src/pages/index/index.vue`. Delete `auth`, `authApply`, and `crmebProduct` exports from `template/admin/src/api/system.js`; `crmebProduct` is used only by the removed commercial page logic.

- [ ] **Step 5: Verify and commit**

Run:

```powershell
php -l crmeb/app/adminapi/controller/Common.php
php -l crmeb/app/adminapi/route/common.php
pwsh -NoProfile -File help/license-alignment/verify-license-alignment.ps1 -Area Authorization
git add -- crmeb/app/adminapi/controller/Common.php crmeb/app/adminapi/route/common.php crmeb/app/services/system/SystemAuthServices.php crmeb/app/adminapi/controller/v1/system/SystemUpgradeClient.php crmeb/crmeb/services/UpgradeService.php crmeb/crmeb/services/UpgradeOpenApiService.php template/admin/src/pages/index/index.vue template/admin/src/api/system.js
git diff --cached --check
git commit -m "refactor: remove commercial authorization flow"
```

Expected: syntax and focused verifier pass; old endpoints resolve to 404 once runtime routes reload.

### Task 5: Retain System Information and Remove Forced Branding Fallbacks

**Files:**
- Move: `template/admin/src/pages/system/auth/index.vue` to `template/admin/src/pages/system/info/index.vue`
- Modify: `crmeb/app/adminapi/route/common.php`
- Modify: `template/admin/src/router/modules/system.js`
- Modify: `template/admin/src/components/copyright/index.vue`
- Modify: `template/admin/src/pages/account/login/index.vue`
- Modify: `template/admin/src/pages/kefu/index.vue`
- Modify: `template/admin/src/pages/kefu/appChat/index.vue`
- Modify: `template/uni-app/pages/index/index.vue`
- Modify: `template/uni-app/pages/annex/special/index.vue`
- Modify: `template/uni-app/pages/user/index.vue`
- Modify: `template/uni-app/pages/users/login/index.vue`
- Delete: `template/uni-app/static/images/support.png`
- Modify: `template/admin/README.md`
- Modify: `template/admin/src/styles/font/iconfont.json`
- Modify: `template/admin/src/styles/font/demo_index.html`

- [ ] **Step 1: Rewrite the former authorization page as local system information**

The retained page imports only local APIs:

```js
import uploadPictures from '@/components/uploadPictures';
import { saveCrmebCopyRight, getCrmebCopyRight, copyrightList } from '@/api/system';
```

On mount it runs only:

```js
this.getCopyRight();
copyrightList().then((res) => {
  this.tableList = res.data;
});
```

Keep environment/permission/process tables and add one local copyright card with an edit button. Remove authorization status, authorization code, captcha, product pricing, purchase/payment methods, QR code code, CRMEB iframe, message listeners, and related imports/state.

- [ ] **Step 2: Rename the route without dropping existing role ownership**

Use this frontend route contract:

```js
{
  path: 'maintain/info',
  name: `${pre}info`,
  meta: {
    auth: ['system-maintain-info'],
    title: '系统信息',
  },
  component: () => import('@/pages/system/info/index'),
}
```

The data cleanup in Task 7 updates menu ID `605` in place, so existing roles continue referencing the same menu ID. Update `template/admin/README.md` from `auth # 商业授权` to `info # 系统信息`.

Keep the existing copyright URLs and methods, but change the runtime route descriptions to `读取版权信息` and `保存版权信息`. Task 7 applies the same neutral descriptions to fresh-install SQL.

- [ ] **Step 3: Render copyright only when configured**

Apply these exact empty-state rules:

- admin copyright component: root `v-if="isShow && copyright"`; remove official-site/community/document links and the default copyright branch;
- admin login and customer-service pages: remove each `v-else` CRMEB copyright/support branch;
- uni-app home/special: remove assignment of `/static/images/support.png` when both values are empty;
- uni-app user page: initialize `copyRightPic: ''`, render the image only with `v-if="copyRightPic"`, and set it only from a nonempty API value;
- uni-app login: remove the default CRMEB link and show the block only when `copyRight` is nonempty.
- after all source references are gone, delete `template/uni-app/static/images/support.png`; its published copy is removed only by the Task 8 rebuild/synchronization step.

Do not rename the backend configuration keys or `/copyright` response fields in this change; preserving existing values is part of the confirmed scope.

- [ ] **Step 4: Run source-level frontend checks**

Run:

```powershell
pwsh -NoProfile -File help/license-alignment/verify-license-alignment.ps1 -Area Branding
rg -n "商业授权|authorize\.crmeb\.net|shop\.crmeb\.net|CRMEB提供技术支持|static/images/support\.png" template/admin/src template/uni-app/pages
Test-Path template/uni-app/static/images/support.png
php -l crmeb/app/adminapi/route/common.php
```

Expected: focused verifier passes; the exact search returns no runtime fallback/authorization match; `Test-Path` returns `False`. Source-header copyright/author attribution is intentionally outside this denylist.

- [ ] **Step 5: Commit the system information and branding changes**

```powershell
git add -- crmeb/app/adminapi/route/common.php template/admin/src/pages/system/auth/index.vue template/admin/src/pages/system/info/index.vue template/admin/src/router/modules/system.js template/admin/src/components/copyright/index.vue template/admin/src/pages/account/login/index.vue template/admin/src/pages/kefu/index.vue template/admin/src/pages/kefu/appChat/index.vue template/admin/src/styles/font/iconfont.json template/admin/src/styles/font/demo_index.html template/uni-app/pages/index/index.vue template/uni-app/pages/annex/special/index.vue template/uni-app/pages/user/index.vue template/uni-app/pages/users/login/index.vue template/uni-app/static/images/support.png template/admin/README.md
git diff --cached --check
git commit -m "refactor: keep local system info without forced branding"
```

### Task 6: Make Official Remote Upgrade Explicit and Default-Off

**Files:**
- Modify: `crmeb/config/upgrade.php`
- Modify: `crmeb/app/services/system/UpgradeServices.php`
- Modify: `crmeb/app/adminapi/controller/UpgradeController.php`
- Modify: `crmeb/app/adminapi/route/system.php`
- Modify: `template/admin/src/api/system.js`
- Modify: `template/admin/src/pages/system/onlineUpgrade/index.vue`
- Modify: `help/docker/.env.application.example`
- Modify: `help/docker/docker-compose.yml`
- Modify: `help/docker/docker-compose.optimized.yml`

- [ ] **Step 1: Add the exact default-off configuration**

At the top of `crmeb/config/upgrade.php` import `think\facade\Env` and add:

```php
'remote_enabled' => Env::get('upgrade.remote_enabled', false),
```

Keep the existing `remote` array but consume every URL through configuration. Add `UPGRADE_REMOTE_ENABLED: "false"` to both Compose PHP service environments and add:

```ini
[UPGRADE]
REMOTE_ENABLED = false
```

to `help/docker/.env.application.example`. Do not edit `crmeb/.env`.

- [ ] **Step 2: Make service construction network-free**

The constructor becomes:

```php
public function __construct(UpgradeLogDao $dao)
{
    $this->dao = $dao;
}
```

Add the shared gate and URL validator:

```php
public function isRemoteEnabled(): bool
{
    return (bool)Config::get('upgrade.remote_enabled', false);
}

protected function assertRemoteEnabled(): void
{
    if (!$this->isRemoteEnabled()) {
        throw new AdminException('官方在线升级服务未启用');
    }
}

protected function getRemoteUrl(string $name): string
{
    $url = (string)Config::get('upgrade.remote.' . $name, '');
    if ($url === '' || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
        Log::warning('Official remote upgrade endpoint rejected', ['name' => $name, 'url' => $url]);
        throw new AdminException('官方在线升级服务地址必须使用 HTTPS');
    }
    return $url;
}
```

Import the existing application `Log` facade for the management log. Add one idempotent lazy initializer that reads `.version`, request host, timestamp, and cached token only after `assertRemoteEnabled()`. It authenticates only when the token is absent. Remove all `http://` URL constants.

- [ ] **Step 3: Gate every remote operation before HTTP**

Call the lazy initializer at the start of `getAuth`, `getUpgradeList`, `getUpgradeableList`, `getAgreement`, `packageDownload`, `download`, `sendUpgradeLog`, and `getUpgradeStatus`. Use these configured names:

```text
login_url
upgrade_url
upgrade_current_url
agreement_url
package_download_url
upgrade_status_url
upgrade_log_url
```

Do not add the gate to `getCrossVersionUpgradeOverview`, `getPendingVersions`, `getAllPendingUpgradeSql`, `executeCrossVersionUpgrade`, `executeAllCrossVersionUpgrade`, `getBackupStatus`, `getUpgradeProgressDetail`, `getRollbackVersions`, or `rollbackToVersion`.

- [ ] **Step 4: Add the local capability endpoint**

Add controller method:

```php
public function capability()
{
    return app('json')->success([
        'remote_enabled' => (bool)Config::get('upgrade.remote_enabled', false),
    ]);
}
```

and authenticated route:

```php
Route::get('upgrade/capability', 'UpgradeController/capability')
    ->option(['real_name' => '在线升级能力']);
```

The endpoint may instantiate `UpgradeServices` through controller injection because its constructor is now side-effect free.

- [ ] **Step 5: Make the management page capability-first**

Add API:

```js
export function upgradeCapabilityApi() {
  return request({
    url: '/system/upgrade/capability',
    method: 'get',
  });
}
```

In the page, initialize `remoteEnabled: null`, await capability in `mounted`, and stop before every existing remote call when false:

```js
async mounted() {
  const response = await upgradeCapabilityApi();
  this.remoteEnabled = response.data.remote_enabled === true;
  if (!this.remoteEnabled) return;
  this.getUpgradeStatus();
  await this.getUpgradeList();
  await this.getupgradeableList();
}
```

Render a neutral disabled-state alert when false; do not show an authorization or domain-purchase prompt.

- [ ] **Step 6: Run focused remote/local verification**

Run:

```powershell
docker compose -f help/docker/docker-compose.optimized.yml build phpfpm
$upgradeProbe = (Resolve-Path 'help/license-alignment/probes/upgrade-remote-policy.php').Path
docker run --rm --network none --mount "type=bind,source=$upgradeProbe,target=/probe/upgrade-remote-policy.php,readonly" tianli/crmeb-php:acceptance php -d open_basedir= /probe/upgrade-remote-policy.php
php -l crmeb/config/upgrade.php
php -l crmeb/app/services/system/UpgradeServices.php
php -l crmeb/app/adminapi/controller/UpgradeController.php
php -l crmeb/app/adminapi/route/system.php
```

Expected: probe passes construction, disabled message, HTTPS validation with one recorded warning for the rejected HTTP URL, and local cross-version overview; all PHP files report no syntax errors.

- [ ] **Step 7: Commit only remote-upgrade files**

```powershell
git add -- crmeb/config/upgrade.php crmeb/app/services/system/UpgradeServices.php crmeb/app/adminapi/controller/UpgradeController.php crmeb/app/adminapi/route/system.php template/admin/src/api/system.js template/admin/src/pages/system/onlineUpgrade/index.vue help/docker/.env.application.example help/docker/docker-compose.yml help/docker/docker-compose.optimized.yml help/license-alignment/probes/upgrade-remote-policy.php
git diff --cached --check
git commit -m "feat: disable official remote upgrades by default"
```

### Task 7: Clean Fresh-Install and Existing Authorization Metadata

**Files:**
- Modify: `crmeb/public/install/crmeb.sql`
- Modify: `crmeb/app/adminapi/controller/UpgradeController.php` (embedded file-integrity inventory only)
- Create: `crmeb/crmeb/command/LicenseAlignmentCleanup.php`
- Modify: `crmeb/config/console.php`

- [ ] **Step 1: Add an idempotent cleanup command**

Register `license:cleanup` in `config/console.php`. The command obtains the active database prefix from `Config`, starts a transaction, and performs only:

```php
Db::name('system_route')->whereIn('url', ['check_auth', 'auth_apply', 'auth'])->delete();
Db::name('system_config')->where('menu_name', 'cert_crmeb')->delete();
Db::name('lang_code')->whereIn('code', ['400330', '400331', '400332', '400333', '400334', '400335'])->delete();
Db::name('system_menus')->where('id', 605)->update([
    'menu_name' => '系统信息',
    'url' => '/system/maintain/info',
    'unique_auth' => 'system-maintain-info',
    'name' => '系统信息',
]);
```

The same transaction removes `system_file_info.full_path` and `system_file_md5.filename` rows for the four deleted source files by exact path. After the transaction commits, delete `upgrade_auth_token` only when `Config::get('upgrade.remote_enabled', false)` is false, then invoke the existing `SystemFileServices::writeMd5()` against the already-verified release tree so the local integrity baseline represents the final distributed PHP files. Output affected row counts. Repeated execution must succeed with zero further authorization/language/file-row deletes and the same menu values.

- [ ] **Step 2: Clean the fresh-install SQL by exact identifiers**

Remove:

- system route rows `121`, `122`, and `123` for `check_auth`, `auth_apply`, and `auth`;
- language-code records with codes `400330` through `400335` for all ten language types, leaving shared captcha/product messages `400336` onward;
- file-tree and MD5 rows for `SystemAuthServices.php`, `SystemUpgradeClient.php`, `UpgradeService.php`, and `UpgradeOpenApiService.php`;
- file-tree rows for `/public/static/images/support.png` and `/public/statics/mp_view/static/images/support.png`;
- any `cert_crmeb` seed record if present.

Update fresh-install route rows `127` and `128` in place to the neutral descriptions `读取版权信息` and `保存版权信息` without changing their URLs, methods, controllers, or actions.

Update menu ID `605` in place to `/system/maintain/info` and `system-maintain-info`. Do not renumber unrelated SQL tuple IDs or rewrite the dump.

- [ ] **Step 3: Synchronize embedded integrity inventory**

Remove deleted-file rows from the embedded inventory in `UpgradeController.php`. Recalculate MD5 only for retained files whose final bytes changed and whose inventory rows remain. Verify every updated hash with:

```powershell
Get-FileHash -Algorithm MD5 -LiteralPath <retained-file-path>
```

The actual implementation records the concrete path/hash pairs in the task evidence before staging; no wildcard update is allowed.

- [ ] **Step 4: Verify idempotency and business-data preservation**

Before running cleanup, capture row counts and deterministic primary-key-ordered digests for:

```text
store_product
store_order
user
system_attachment
diy
```

Run:

```powershell
docker compose -f help/docker/docker-compose.optimized.yml exec -T phpfpm php think license:cleanup
docker compose -f help/docker/docker-compose.optimized.yml exec -T phpfpm php think license:cleanup
```

Expected: first run reports only named authorization, language, menu, file-inventory, MD5-baseline, and cache changes; second run reports zero authorization/language/file-row deletes and the already-final menu update; all five business table counts/digests and upload snapshot are unchanged. Run this command only against the verified release tree because rebuilding the MD5 baseline intentionally trusts those final PHP bytes. Do not restore the authorization metadata after verification; do restore any unrelated login/cache test state.

- [ ] **Step 5: Verify and commit data cleanup**

```powershell
php -l crmeb/crmeb/command/LicenseAlignmentCleanup.php
php -l crmeb/config/console.php
pwsh -NoProfile -File help/license-alignment/verify-license-alignment.ps1 -Area InstallData
git add -- crmeb/public/install/crmeb.sql crmeb/app/adminapi/controller/UpgradeController.php crmeb/crmeb/command/LicenseAlignmentCleanup.php crmeb/config/console.php
git diff --cached --check
git commit -m "chore: remove authorization metadata from installs"
```

### Task 8: Build, Synchronize, and Perform End-to-End Acceptance

**Files:**
- Modify: `crmeb/public/admin/index.html`
- Modify/Create/Delete: `crmeb/public/admin/system_static/**` according to the new management build manifest
- Modify: `crmeb/public/index.html`
- Modify/Create/Delete: `crmeb/public/static/**` according to a fresh `template/uni-app` H5 build manifest
- Modify/Create/Delete: `crmeb/public/statics/mp_view/**` according to a fresh `template/uni-app` `mp-weixin` build manifest
- Modify: `crmeb/public/install/crmeb.sql` only for exact generated-file inventory differences produced by the verified builds
- Modify: `openspec/changes/align-apache-license-and-disable-vendor-auth/tasks.md`

- [ ] **Step 1: Build the management client, H5, and mini-program**

Run:

```powershell
npm ci
npm run build
```

Working directory: `template/admin`.

Expected: production build exits `0` and creates `template/admin/dist/index.html` plus hashed assets. The build must not edit source files.

Build `template/uni-app` for both H5 and `mp-weixin` with one fixed official HBuilderX/uni-app compiler version and record that version and the exact publish commands. This source tree has no npm build script, so do not invent or commit a replacement package manifest. The expected ignored outputs are `template/uni-app/unpackage/dist/build/h5` and `template/uni-app/unpackage/dist/build/mp-weixin`; H5 must contain `index.html` and `static/`, while the mini-program must contain `app.js`, `app.json`, `project.config.json`, and the compiled page files. If the official compiler cannot produce both complete builds, stop this task as incomplete; do not patch minified output to make the verifier pass.

- [ ] **Step 2: Synchronize through a validated staging directory**

Resolve all absolute paths. Assert the admin source equals `H:\天俪商城\CRMEB\template\admin\dist` and destination equals `H:\天俪商城\CRMEB\crmeb\public\admin`; assert the H5 source equals `H:\天俪商城\CRMEB\template\uni-app\unpackage\dist\build\h5`; assert the mini-program source equals `H:\天俪商城\CRMEB\template\uni-app\unpackage\dist\build\mp-weixin` and destination equals `H:\天俪商城\CRMEB\crmeb\public\statics\mp_view`. Copy each completed build to a separate temporary sibling staging directory and verify its required entry files. Mirror the complete admin and mini-program staging trees into their exact destinations. For H5, mirror only the staged `static/` tree to `crmeb/public/static/` and replace only `crmeb/public/index.html`; never mirror an H5 root over `crmeb/public/`. Remove only destination assets absent from the corresponding verified build; never delete a computed, unresolved, workspace-root, or user-data path.

Expected: old `app.dc1bf06a.js` and `chunk-55fbd112.733791df.js` are absent if the new admin entry manifest no longer references them; the H5 and `mp_view` trees have no compiled fallback to `/static/images/support.png`; all three source/published support images are absent; and no minified file is hand-edited. Update fresh-install file inventory only for exact added, removed, renamed, or rehashed files proven by the three build-manifest comparisons.

- [ ] **Step 3: Run complete static, syntax, OpenSpec, and runtime checks**

After all release bytes are synchronized, run `php think license:cleanup` once more in the application container. It must report zero further authorization, language, configuration, and deleted-file row removals while rebuilding the local MD5 baseline from the final verified tree. Then run:

```powershell
docker compose -f help/docker/docker-compose.optimized.yml exec -T phpfpm php think license:cleanup
pwsh -NoProfile -File help/license-alignment/tests/LicenseAlignment.Tests.ps1
pwsh -NoProfile -File help/license-alignment/verify-license-alignment.ps1
Get-ChildItem crmeb/app,crmeb/crmeb,crmeb/config,crmeb/upgrade -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
openspec validate align-apache-license-and-disable-vendor-auth
pwsh -NoProfile -File help/docker/verify-php82.ps1
pwsh -NoProfile -File help/docker/verify-optimized.ps1
```

Expected: all commands exit `0`; source and active published assets contain no commercial authorization or forced CRMEB fallback; existing PHP 8.2/runtime acceptance remains green.

- [ ] **Step 4: Perform authenticated browser acceptance**

With the local stack running, log in and verify:

1. Dashboard metrics load and Network contains no `auth`, `check_auth`, `auth_apply`, `authorize.crmeb.net`, `shop.crmeb.net`, or `upgrade.crmeb.net` request.
2. System Information loads environment diagnostics and can read/save the existing local copyright fields.
3. Empty copyright values hide admin, login, customer-service, and storefront copyright blocks; configured values render correctly.
4. Online Upgrade shows the disabled state after one local capability call and makes no other upgrade request.
5. Cross-Version Upgrade overview, backup status, progress, and rollback-version list remain locally reachable.
6. Storefront homepage still shows the restored demo products and returns business `status=200` for copyright, theme, home-layout, and language bootstrap APIs.

- [ ] **Step 5: Compare data and upload evidence**

Recompute the five business table digests and upload snapshot from Task 7. Expected: exact equality with the pre-change evidence. Also record product count and homepage product API count so a visually empty homepage cannot pass only on database presence.

- [ ] **Step 6: Mark OpenSpec tasks complete and commit artifacts**

Mark a task complete only after its check above is recorded. Re-run OpenSpec validation, then:

```powershell
git add -- crmeb/public/admin crmeb/public/index.html crmeb/public/static crmeb/public/statics/mp_view crmeb/public/install/crmeb.sql openspec/changes/align-apache-license-and-disable-vendor-auth/tasks.md
git diff --cached --check
git status --short
git commit -m "build: publish authorization-free client assets"
```

Expected before commit: `.env`, `.constant`, `.codex/`, PHP 8.2 compatibility work, prototype documents, and unrelated OpenSpec changes are not staged.

## Final Acceptance Matrix

| Area | Required evidence |
| --- | --- |
| License | 0 project-owned restrictive headers; three full Apache-2.0 license copies; copyright/author lines retained |
| Authorization | old routes return 404; no dashboard/system-info authorization request; no commercial purchase UI |
| Branding | configured local text/image render; empty values produce no CRMEB fallback |
| Remote upgrade | capability `status=200`; disabled remote calls `status=400`; zero CRMEB requests; HTTPS-only when enabled |
| Local upgrade | cross-version overview, SQL preview, backup, progress, and rollback work with remote disabled |
| Installer/data | no proprietary EULA or completion telemetry; cleanup is idempotent; authorization metadata absent |
| Commerce/demo | product/order/user/upload/homepage-decoration digests unchanged; demo products still visible |
| Distribution | management, H5, and mini-program builds succeed; active published assets match source; stale authorization chunks and support-image fallback are removed |
| Specification | `openspec validate align-apache-license-and-disable-vendor-auth` exits `0` |
