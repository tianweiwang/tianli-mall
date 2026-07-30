## Context

The repository root contains Apache License 2.0, while project-owned source headers, `crmeb/LICENSE.txt`, `help/resource/LICENSE.txt`, and the installer still contain restrictive proprietary statements. The application also contains commercial authorization queries and applications, an automatic dashboard check, purchase UI, forced CRMEB branding fallbacks, installer completion telemetry, and remote-upgrade code that can read authorization state or contact CRMEB during service construction.

The confirmed distribution decision applies to project-owned code and license surfaces only. Copyright and author attribution remain, and third-party packages, licenses, NOTICE files, and trademarks remain governed by their original terms. This design does not assert that third-party code has been relicensed and does not bypass authentication or eligibility checks on an explicitly enabled official service.

The current workspace includes unrelated PHP 8.2 and OpenSpec work. Implementation SHALL preserve those changes and SHALL NOT read, edit, or stage `crmeb/.env` or `crmeb/.constant`.

## Goals / Non-Goals

**Goals:**

- Make every project-owned license statement consistent with Apache-2.0 while retaining applicable copyright and author lines.
- Remove the commercial authorization runtime, purchase/application surfaces, forced CRMEB branding fallbacks, and installation telemetry.
- Preserve local system diagnostics and the existing local copyright configuration and `/copyright` response contract.
- Make official remote upgrade access explicit, default-off, lazy, HTTPS-only when enabled, and network-free when disabled.
- Keep local cross-version upgrade, backup, progress, and rollback operations usable without remote login.
- Apply deterministic, idempotent authorization-metadata cleanup without changing commerce, uploaded content, homepage decoration, or demo data.

**Non-Goals:**

- Relicense, rewrite, or remove third-party licenses or attribution.
- Remove ordinary login, token, role, menu-permission, or OAuth enforcement.
- Bypass official upgrade authentication, signatures, eligibility, or service terms when remote access is explicitly enabled.
- Remove payment, SMS, logistics, cloud storage, WeChat, or other user-configured business integrations.
- Rename local copyright configuration keys, change `/copyright` response fields, rebrand the product generally, upgrade dependencies, or refactor commerce behavior.

## Decisions

### Align only project-owned license surfaces

The root `LICENSE` SHALL remain the complete Apache License 2.0 text. `crmeb/LICENSE.txt` and `help/resource/LICENSE.txt` SHALL carry the same complete license so independently distributed trees contain the governing terms. The installer agreement SHALL present Apache-2.0 rights, attribution and change-notice duties, warranty disclaimer, trademark exclusion, and the bundled license location without adding a commercial authorization requirement.

The exact project-owned restrictive source-header line SHALL be mechanically replaced with `Licensed under the Apache License, Version 2.0`. The replacement SHALL preserve every existing copyright and author line and SHALL exclude `vendor/`, dependency trees, generated output, and third-party license or NOTICE files. No changed document SHALL claim that third-party code is relicensed.

### Remove commercial authorization and installation telemetry

The commercial `check_auth`, `auth_apply`, and `auth` routes, their controller/service implementation, dashboard automatic check, management API wrappers, application/purchase/price/payment UI, and CRMEB marketplace iframe SHALL be removed. Former endpoints SHALL resolve as not found rather than return fabricated authorization success. Ordinary application authentication and authorization SHALL remain unchanged.

Legacy upgrade clients whose only role is domain authorization, file synchronization, or site telemetry SHALL be removed after caller verification. The installer completion page SHALL perform only local rendering and navigation and SHALL NOT report domain, URL, version, platform, referral, or IP information to CRMEB.

### Preserve local system information and copyright configuration

The former commercial-authorization page SHALL become a local system-information surface. It SHALL retain environment and directory diagnostics plus the existing local copyright text/image editor, and it SHALL NOT load authorization state, purchase data, captcha, pricing, QR payment, or remote CRMEB content.

The existing `GET /copyright` and `POST /copyright` routes, status envelope, response field names, configuration keys, and stored values SHALL remain compatible. Clients SHALL render configured local copyright text or image. Empty or unavailable configuration SHALL hide the branding region or use an already configured local site name/logo; it SHALL NOT fall back to CRMEB support text, links, or images.

The runtime route metadata and fresh-install route metadata SHALL describe these operations neutrally as `读取版权信息` and `保存版权信息` without changing their URLs, methods, response fields, or stored values.

Menu metadata for ID `605` SHALL be updated in place to the neutral system-information title, route, and permission so existing role references retain the same menu ID. Local copyright records SHALL NOT be deleted or renamed as authorization metadata.

### Gate every official remote upgrade operation

`config/upgrade.php` SHALL read `Env::get('upgrade.remote_enabled', false)`, corresponding to deployment variable `UPGRADE_REMOTE_ENABLED=false`. Absence and false SHALL both disable official remote upgrade access.

`UpgradeServices` construction SHALL only retain local dependencies. It SHALL NOT read `.version`, the request host, `upgrade_auth_token`, or initialize an HTTP request. A shared gate SHALL run before remote authentication, list, agreement, status, package download, or log methods can read remote state. When disabled, those methods SHALL return business `status=400` with `官方在线升级服务未启用` before any request to a CRMEB domain.

`GET /system/upgrade/capability` SHALL be a local authenticated endpoint returning business `status=200` and a boolean `data.remote_enabled`. The management page SHALL call this endpoint first and SHALL stop before all other online-upgrade calls when disabled.

When explicitly enabled, remote authentication and token lookup SHALL be initialized lazily. Every official endpoint SHALL come from configuration and SHALL use HTTPS; invalid or missing HTTPS configuration SHALL fail before network access and SHALL record a management log. Existing official authentication, signature, and package-eligibility decisions SHALL remain enforced.

### Keep local upgrade operations independent

Local cross-version inspection, pending-version inspection, SQL preview, database/project backup, execution, progress reporting, rollback inspection, and rollback execution SHALL NOT pass through the remote-upgrade gate or require remote login. Disabling official remote upgrade SHALL affect only official network-backed operations.

### Restrict installation and existing-environment cleanup

Fresh-install SQL SHALL omit the `check_auth`, `auth_apply`, and `auth` route metadata, `cert_crmeb`, authorization-only translations, and inventory rows for deleted authorization/telemetry files. Menu ID `605` SHALL contain the final system-information metadata. Integrity hashes for retained changed files SHALL be recalculated from final bytes rather than disabling integrity checking.

The existing-environment cleanup SHALL be transactional, prefix-aware, idempotent, and limited to these named records:

- `system_config.menu_name = cert_crmeb`;
- `system_route.url IN (check_auth, auth_apply, auth)`;
- authorization-only translation codes `400330` through `400335`;
- menu ID `605` title, route, and permission metadata;
- file-tree and MD5 inventory rows for the four deleted authorization/telemetry classes;
- fresh-install file-tree rows for `/public/static/images/support.png` and `/public/statics/mp_view/static/images/support.png`;
- Redis key `upgrade_auth_token`, and only while remote upgrade is disabled.

After the database transaction, any integrity-baseline regeneration SHALL operate only on the already verified release tree. The cleanup SHALL NOT change local copyright configuration, products, orders, users, payments, attachments/uploads, homepage decoration, or demo records. Before/after deterministic row digests and upload hashes SHALL provide evidence of preservation, and a second cleanup run SHALL produce no further authorization-data deletion.

### Publish rebuilt management, H5, and mini-program assets

Management assets SHALL be produced from source with the existing production build. After all uni-app source references to the CRMEB support-image fallback are removed, `template/uni-app/static/images/support.png` SHALL be deleted. Fresh H5 and `mp-weixin` outputs SHALL be produced with one fixed official HBuilderX/uni-app compiler version whose version and exact publish commands are recorded. The implementation SHALL NOT invent an npm build script for the uni-app tree.

Management, H5, and `mp-weixin` outputs SHALL each be synchronized through a separately validated staging directory. The management output SHALL be mirrored only to `crmeb/public/admin`. The H5 `static/` tree SHALL be mirrored only to `crmeb/public/static`, and its `index.html` SHALL replace only `crmeb/public/index.html`; the H5 root SHALL NOT be mirrored over `crmeb/public`. The complete verified mini-program output SHALL be mirrored to `crmeb/public/statics/mp_view` only after required entry files are verified.

Synchronization SHALL remove only stale generated assets absent from the corresponding verified build. The source image, `crmeb/public/static/images/support.png`, and `crmeb/public/statics/mp_view/static/images/support.png` SHALL all be absent after synchronization. Minified output SHALL NOT be edited by hand. Active source and published assets SHALL contain no commercial authorization endpoint, purchase UI, installer telemetry, or forced CRMEB branding fallback.

## Error Handling

- Disabled remote upgrade, invalid HTTPS configuration, authentication failure, and official eligibility failure SHALL remain distinguishable outcomes; rejecting invalid HTTPS configuration SHALL record a management log before returning the error.
- Copyright configuration failure SHALL NOT create or display a fabricated CRMEB fallback.
- Cleanup failure SHALL roll back its database transaction; repeated successful cleanup SHALL be a no-op for already removed authorization records.
- Build or synchronization failure SHALL preserve the previous published directory and SHALL NOT be reported as released.

## Verification

1. Run a deterministic source and published-asset denylist that excludes historical specifications and third-party licenses but rejects restrictive project-owned license text, commercial authorization routes/domains, installer telemetry, and forced CRMEB branding fallbacks.
2. Verify exact source-header replacement counts and byte preservation, full Apache-2.0 license copies, retained copyright/author attribution, and untouched third-party license files.
3. Under network isolation, verify service construction, capability response, disabled remote method responses, zero CRMEB requests, HTTPS endpoint validation, and all local cross-version operations.
4. Run cleanup twice against fixtures and the approved environment; compare exact authorization-record changes plus deterministic commerce-table and upload evidence.
5. Run focused PHP syntax/tests, the management production build, the recorded official H5 and `mp-weixin` builds, browser network and visible-state checks, existing PHP 8.2/runtime acceptance, and strict OpenSpec validation.

## Rollback

Code, configuration, menu metadata, and generated management assets can be reverted through normal version control and database backup procedures. Commerce and demo records require no rollback because this change SHALL NOT mutate them. Reintroducing proprietary restrictions or hidden commercial gates is outside rollback; a changed licensing decision requires a separate reviewed specification.
