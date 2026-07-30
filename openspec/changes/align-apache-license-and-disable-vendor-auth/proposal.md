## Why

The repository root distributes Apache License 2.0 and the README permits commercial use, but project-owned source headers, nested license files, the installer, management surfaces, and upgrade code still express or enforce a separate proprietary commercial-authorization model. The conflicting surfaces create an ambiguous distribution contract and cause default local operation to contact CRMEB services unnecessarily.

This change aligns project-owned licensing and runtime behavior with the repository license while preserving applicable copyright and author attribution. It does not relicense third-party code, grant trademark rights, bypass official service authentication, or change ordinary commerce data and integrations.

## What Changes

- Replace project-owned restrictive license statements and nested proprietary licenses with Apache-2.0 while retaining copyright and author attribution.
- Remove commercial authorization queries, applications, purchases, automatic dashboard checks, forced CRMEB branding fallbacks, and installer completion telemetry.
- Keep local copyright text/image configuration and its existing `/copyright` response contract.
- Disable official remote upgrades by default with `UPGRADE_REMOTE_ENABLED=false`; when disabled, no token lookup or CRMEB network request occurs.
- Keep local cross-version upgrades, backups, and rollback independent from official remote authorization.
- Remove only exact authorization metadata from fresh-install and existing-environment data; commerce and demo data remain unchanged.

## Capabilities

### New Capabilities

- `apache-license-alignment`: Defines the license, authorization-removal, local copyright, default-off remote-upgrade, offline local-upgrade, and narrow data-cleanup contract for the distributed application.

## Impact

- Project-owned license surfaces: root-adjacent application licenses, installer agreement, and tracked project-owned source headers.
- Runtime authorization surfaces: commercial authorization services and routes, dashboard checks, purchase/application UI, legacy telemetry clients, installer completion reporting, and compiled management/H5/mini-program assets.
- Preserved local surfaces: system diagnostics, the existing `/copyright` URL and response fields, local cross-version inspection and execution, backups, progress, and rollback.
- Deployment: `UPGRADE_REMOTE_ENABLED` is absent or false by default; official endpoints remain optional HTTPS services subject to their existing authentication when explicitly enabled.
- Data: only named authorization configuration, route/translation/menu metadata, deleted authorization/telemetry or forced-fallback file inventory, and the cached remote token are eligible for cleanup; products, orders, users, uploads, homepage decoration, and demo data are outside the change.
- Third-party licenses and attribution remain governed by their original terms and are not modified or represented as relicensed by this change.
