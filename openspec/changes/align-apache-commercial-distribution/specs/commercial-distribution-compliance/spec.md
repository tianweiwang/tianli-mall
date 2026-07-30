## ADDED Requirements

### Requirement: Single Apache-2.0 software license
The distribution SHALL present Apache License 2.0 as its only software-use license and SHALL NOT impose a commercial authorization, mandatory footer link, or source-modification restriction beyond that license.

#### Scenario: License text is inspected
- **WHEN** a user reads the root license, application license, installer agreement, README, or a project-owned source header
- **THEN** those surfaces SHALL consistently identify Apache License 2.0
- **THEN** they SHALL NOT state that the software is non-free, require purchase of commercial authorization, or forbid removing a runtime branding link
- **THEN** existing upstream copyright and author attribution SHALL remain intact

#### Scenario: Third-party attribution is inspected
- **WHEN** dependency, framework, or third-party license files are reviewed
- **THEN** their original license and attribution SHALL remain unmodified
- **THEN** no CRMEB trademark right SHALL be represented as transferred by Apache License 2.0

### Requirement: No commercial authorization runtime
The application SHALL operate without a CRMEB commercial authorization query, application, certificate, purchase flow, or authorization-dependent feature gate.

#### Scenario: Administrator opens the dashboard
- **WHEN** an authenticated administrator opens the dashboard
- **THEN** the client SHALL NOT call `check_auth`, `auth_apply`, `auth`, or a CRMEB authorization domain
- **THEN** dashboard business metrics SHALL remain available under the ordinary role and token middleware

#### Scenario: Removed endpoints are requested
- **WHEN** a caller requests a former commercial authorization endpoint
- **THEN** routing SHALL return not found
- **THEN** the application SHALL NOT return a fabricated successful authorization response
- **THEN** no authorization certificate SHALL be written to system configuration

#### Scenario: Ordinary authentication is used
- **WHEN** an administrator, customer-service user, storefront user, or WeChat user authenticates normally
- **THEN** login tokens, role permissions, menu permissions, and OAuth behavior SHALL remain enforced

### Requirement: Local system information remains available
The former authorization page SHALL become a local system-information page without commercial or remote dependencies.

#### Scenario: Existing authorized role opens system information
- **WHEN** a role that owns menu ID `605` opens the migrated menu
- **THEN** the role SHALL retain access through the same menu ID
- **THEN** the route, permission code, title, tab, breadcrumb, and search result SHALL identify `系统信息`
- **THEN** the page SHALL request only the local `system/info` diagnostics and `/copyright` site-branding APIs
- **THEN** an administrator with the existing page permission SHALL be able to edit local site-branding text and image without a commercial authorization state

### Requirement: Neutral local site branding
The application SHALL retain configurable site copyright text and image without requiring or falling back to CRMEB branding.

#### Scenario: Branding is configured
- **WHEN** an administrator saves site copyright text or an image
- **THEN** the values SHALL be stored under neutral site-branding keys
- **THEN** admin, login, customer-service, PC, H5, and mini-program consumers SHALL display the configured values
- **THEN** the public `/copyright` response SHALL keep its compatible URL and response fields with business `status=200`

#### Scenario: Branding is empty or unavailable
- **WHEN** both branding values are empty or the branding request fails
- **THEN** clients SHALL render no CRMEB support text, CRMEB URL, or CRMEB fallback image
- **THEN** clients MAY render configured site name/logo or hide the branding region

#### Scenario: Existing branding is migrated
- **WHEN** the migration finds a legacy CRMEB-named branding key
- **THEN** its value SHALL be preserved under the neutral key if that target does not exist
- **THEN** an existing neutral target value SHALL take precedence over a duplicate legacy value

### Requirement: Official remote upgrades are explicit and default-off
Official remote upgrade access SHALL be optional, disabled by default, network-free while disabled, and independent from local cross-version operations.

#### Scenario: Remote upgrade is disabled
- **WHEN** `UPGRADE.OFFICIAL_REMOTE_ENABLED` is absent or false
- **THEN** constructing the upgrade service and opening the online-upgrade page SHALL make zero request to an official upgrade domain
- **THEN** remote list, agreement, download, status, authentication, and log operations SHALL fail locally with an explicit disabled message before HTTP access

#### Scenario: Remote upgrade is enabled
- **WHEN** a deployer explicitly enables official remote upgrade with complete configuration
- **THEN** the service SHALL authenticate lazily and use only configured HTTPS endpoints
- **THEN** existing official authentication, signature, and package-eligibility decisions SHALL remain enforced
- **THEN** failure SHALL NOT downgrade to plaintext HTTP or fabricate eligibility

#### Scenario: Local cross-version operation runs offline
- **WHEN** remote upgrade is disabled and an administrator checks, previews, backs up, executes, monitors, or rolls back a local cross-version migration
- **THEN** that operation SHALL remain available without remote authentication or an external request

### Requirement: Fresh installs and existing databases contain no authorization residue
Both fresh installation data and an idempotent existing-database migration SHALL remove commercial authorization metadata without changing business or demo data.

#### Scenario: Fresh installation is inspected
- **WHEN** the installer schema, API documentation seed, translations, file tree, and MD5 inventory are loaded
- **THEN** they SHALL contain no commercial authorization routes, certificate configuration, authorization-only message, or deleted authorization file entry
- **THEN** every retained file-integrity entry SHALL match the final distributed file

#### Scenario: Existing database is migrated twice
- **WHEN** the commercial-distribution migration runs twice with the configured table prefix
- **THEN** the first run SHALL remove only the named authorization metadata, migrate branding keys, and update menu ID `605`
- **THEN** the second run SHALL succeed with no further data changes
- **THEN** product, order, user, upload, and example-data evidence SHALL remain unchanged

### Requirement: Published assets match compliant source
Production frontend assets SHALL contain the same authorization-free and neutral-branding behavior as their source.

#### Scenario: Frontends are rebuilt
- **WHEN** management and reproducible storefront targets are built for release
- **THEN** active entry manifests SHALL reference only newly built assets
- **THEN** stale unreferenced hashed chunks containing removed authorization or branding fallback code SHALL be removed
- **THEN** a denylist scan of source and published assets SHALL find no commercial authorization endpoint, authorization domain, restrictive license statement, or forced CRMEB support fallback outside explicitly allowed historical and third-party evidence

### Requirement: Business data and core commerce behavior are preserved
Commercial-distribution cleanup SHALL NOT mutate commerce records or remove unrelated optional integrations.

#### Scenario: Acceptance evidence is compared
- **WHEN** pre-change and post-change database/upload evidence is compared
- **THEN** products, orders, users, uploaded files, and example data SHALL be unchanged
- **THEN** payment, SMS, logistics, storage, and WeChat integrations SHALL remain present unless separately configured off
- **THEN** storefront copyright, theme, home-layout, and language bootstrap APIs SHALL continue returning valid JSON with business `status=200`
