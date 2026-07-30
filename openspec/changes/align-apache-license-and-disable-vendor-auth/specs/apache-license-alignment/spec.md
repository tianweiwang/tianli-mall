## ADDED Requirements

### Requirement: Project-owned Apache-2.0 license alignment
Every project-owned software-license surface SHALL identify Apache License 2.0 without adding a commercial authorization, mandatory branding link, or source-modification restriction, while applicable copyright and author attribution SHALL remain intact.

#### Scenario: Project-owned license surfaces are inspected
- **WHEN** the root license, nested project-owned application licenses, installer agreement, README, and tracked project-owned source headers are inspected
- **THEN** each software-license statement SHALL be consistent with Apache License 2.0
- **THEN** no statement SHALL call the software non-free, require a commercial authorization purchase, bind use to one domain, forbid modification or redistribution, or require a runtime CRMEB branding link
- **THEN** existing copyright and author attribution SHALL remain present

#### Scenario: Third-party license surfaces are inspected
- **WHEN** a dependency, framework, third-party resource, license, or NOTICE file is inspected
- **THEN** its original license and attribution SHALL remain unmodified
- **THEN** the distribution SHALL NOT represent that third-party code or a CRMEB trademark has been relicensed by this change

### Requirement: Commercial authorization and installer telemetry are absent
The application SHALL operate without a CRMEB commercial authorization query, application, purchase flow, certificate gate, automatic dashboard check, legacy authorization telemetry client, or installer completion report.

#### Scenario: Administrator opens the dashboard
- **WHEN** an authenticated administrator opens the dashboard
- **THEN** the client SHALL NOT call `check_auth`, `auth_apply`, `auth`, a CRMEB authorization domain, or a CRMEB marketplace purchase endpoint
- **THEN** dashboard commerce metrics SHALL remain available through ordinary role and token middleware

#### Scenario: Former authorization endpoint is requested
- **WHEN** a caller requests `check_auth`, `auth_apply`, or `auth`
- **THEN** routing SHALL return not found
- **THEN** the application SHALL NOT fabricate a successful authorization response
- **THEN** no authorization certificate SHALL be written to local configuration

#### Scenario: Installation completes
- **WHEN** a fresh local installation reaches its completion page
- **THEN** the page SHALL perform only local rendering and navigation
- **THEN** it SHALL NOT report a domain, URL, version, platform, referral identifier, or IP address to CRMEB

#### Scenario: Ordinary authentication and integrations are used
- **WHEN** administrators, customer-service users, storefront users, or WeChat users authenticate or invoke a configured business integration
- **THEN** login tokens, role permissions, menu permissions, and OAuth behavior SHALL remain enforced
- **THEN** payment, SMS, logistics, storage, and WeChat integrations SHALL remain available unless independently configured off

### Requirement: Local system information and copyright remain available
The application SHALL retain local environment diagnostics and local copyright text/image configuration without a commercial authorization state or forced CRMEB branding fallback.

#### Scenario: Existing role opens system information
- **WHEN** a role that owns menu ID `605` opens the migrated system-information page
- **THEN** the role SHALL retain access through menu ID `605`
- **THEN** the page SHALL load only local environment diagnostics and local copyright configuration
- **THEN** the page SHALL NOT load authorization status, captcha, product price, purchase/payment UI, or a CRMEB iframe

#### Scenario: Local copyright is configured
- **WHEN** an administrator reads or saves local copyright text or image
- **THEN** the existing `GET /copyright` and `POST /copyright` routes SHALL remain available
- **THEN** the existing configuration keys and stored values SHALL remain unchanged
- **THEN** the existing status envelope and response field names SHALL remain compatible
- **THEN** configured values SHALL be rendered by their existing management and storefront consumers

#### Scenario: Local copyright is empty or unavailable
- **WHEN** local copyright text/image is empty or its request fails
- **THEN** clients SHALL NOT render CRMEB support text, a CRMEB URL, or a CRMEB fallback image
- **THEN** clients SHALL hide the copyright region unless an already configured local site name or logo is rendered

### Requirement: Official remote upgrade is explicit, default-off, and lazy
Official remote upgrade access SHALL be disabled by default, SHALL perform no token lookup or CRMEB request while disabled, and SHALL initialize official authentication only after explicit enablement.

#### Scenario: Official remote upgrade is disabled
- **WHEN** `UPGRADE_REMOTE_ENABLED` is absent or false
- **THEN** `GET /system/upgrade/capability` SHALL return business `status=200` with `data.remote_enabled=false`
- **THEN** constructing the upgrade service SHALL NOT read `upgrade_auth_token`
- **THEN** remote upgrade methods SHALL return business `status=400` with `官方在线升级服务未启用`
- **THEN** no request to a CRMEB domain SHALL occur

#### Scenario: Official remote upgrade is enabled
- **WHEN** a deployer explicitly sets `UPGRADE_REMOTE_ENABLED=true` with complete official endpoint configuration
- **THEN** token lookup and authentication SHALL occur lazily only when a remote operation is requested
- **THEN** every official endpoint SHALL be read from configuration and SHALL use HTTPS
- **THEN** invalid or missing HTTPS configuration SHALL fail before network access
- **THEN** existing official authentication, signature, and package-eligibility decisions SHALL remain enforced

#### Scenario: Online-upgrade page is opened while disabled
- **WHEN** an administrator opens the online-upgrade page while official remote upgrade is disabled
- **THEN** the page SHALL make only the local capability request
- **THEN** it SHALL display a neutral disabled state and SHALL NOT request remote status, list, agreement, package, authentication, or log operations

### Requirement: Local upgrade is independent from official remote authorization
Local cross-version operations, backups, execution state, and rollback SHALL remain separate from the official remote-upgrade gate.

#### Scenario: Local upgrade remains available
- **WHEN** official remote upgrade is disabled
- **THEN** local cross-version inspection, SQL preview, backup, execution, progress, and rollback SHALL remain available
- **THEN** those operations SHALL NOT require remote login

#### Scenario: Local upgrade is exercised under network isolation
- **WHEN** local cross-version operations run with external networking unavailable
- **THEN** they SHALL complete or return local validation results without a CRMEB request
- **THEN** a remote-upgrade disabled state SHALL NOT be reported as a local backup, execution, progress, or rollback failure

### Requirement: Authorization metadata cleanup is exact and idempotent
Fresh-install data and existing-environment cleanup SHALL contain no commercial authorization metadata and SHALL modify only explicitly named authorization-related records.

The named cleanup set SHALL consist only of `system_config.menu_name=cert_crmeb`, `system_route.url` values `check_auth`, `auth_apply`, and `auth`, authorization-only translation codes `400330` through `400335`, menu ID `605` system-information metadata, file-tree and MD5 inventory rows for deleted authorization/telemetry classes, fresh-install file-tree rows for `/public/static/images/support.png` and `/public/statics/mp_view/static/images/support.png`, and cached `upgrade_auth_token` while remote upgrade is disabled.

#### Scenario: Fresh installation metadata is inspected
- **WHEN** fresh-install SQL, route documentation, translations, menu metadata, file tree, and MD5 inventory are loaded
- **THEN** they SHALL contain no `cert_crmeb`, `check_auth`, `auth_apply`, `auth`, authorization-only translation, deleted authorization/telemetry file entry, `/public/static/images/support.png` entry, or `/public/statics/mp_view/static/images/support.png` entry
- **THEN** menu ID `605` SHALL identify the local system-information title, route, and permission
- **THEN** every retained file-integrity entry SHALL match the final distributed file bytes

#### Scenario: Existing cleanup runs repeatedly
- **WHEN** cleanup runs twice with the configured database table prefix
- **THEN** the first run SHALL change only the named cleanup set
- **THEN** the second run SHALL remove no additional authorization configuration, route, translation, or file-inventory record
- **THEN** local copyright configuration keys and values SHALL remain unchanged

#### Scenario: Cached token cleanup is considered
- **WHEN** cleanup reaches cached remote authorization state
- **THEN** `upgrade_auth_token` SHALL be deleted only when official remote upgrade is disabled
- **THEN** a database cleanup failure SHALL NOT be hidden by cache cleanup

#### Scenario: Commerce data is preserved
- **WHEN** cleanup is applied to an existing environment
- **THEN** only the named authorization configuration, route metadata, authorization-only translations, menu metadata, file-integrity inventory, and cached remote token SHALL change
- **THEN** products, orders, users, uploads, homepage decoration, and demo data SHALL remain byte-for-byte or row-digest equivalent

### Requirement: Published assets match authorization-free source
Active management, H5, and mini-program production assets SHALL implement the same commercial-authorization removal and local copyright behavior as their source.

#### Scenario: Management, H5, and mini-program assets are rebuilt
- **WHEN** management source and uni-app source are built and synchronized for release
- **THEN** management entry manifests SHALL reference the completed new management build
- **THEN** fresh H5 and `mp-weixin` trees SHALL be produced by one fixed official HBuilderX/uni-app compiler version with its exact publish commands recorded
- **THEN** only the H5 `static/` tree SHALL be mirrored to `crmeb/public/static` and only its `index.html` SHALL replace `crmeb/public/index.html`
- **THEN** the complete verified `mp-weixin` tree SHALL be mirrored to `crmeb/public/statics/mp_view`
- **THEN** only stale generated assets absent from each verified build SHALL be removed
- **THEN** minified output SHALL NOT be edited by hand

#### Scenario: Source and published assets are scanned
- **WHEN** project-owned runtime source and active published assets are checked
- **THEN** they SHALL contain no commercial authorization endpoint or purchase flow, installer completion telemetry, or forced CRMEB support fallback
- **THEN** `template/uni-app/static/images/support.png`, `crmeb/public/static/images/support.png`, and `crmeb/public/statics/mp_view/static/images/support.png` SHALL all be absent
- **THEN** historical specifications, applicable copyright/author attribution, and third-party license evidence SHALL remain outside the denial condition
