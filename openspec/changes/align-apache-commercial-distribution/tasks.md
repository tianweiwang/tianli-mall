## 1. Specification and red baseline

- [ ] 1.1 Validate the proposal, design, specification delta, and task list before implementation.
- [ ] 1.2 Add a focused compliance test that scans project-owned source, installer metadata, routes, UI source, and published assets while excluding third-party licenses and historical specifications.
- [ ] 1.3 Run the compliance test against the current tree and record expected failures for the restrictive license line, commercial authorization chain, legacy branding keys/fallbacks, and compiled chunks.
- [ ] 1.4 Add focused tests for default-off official upgrades, network-free service construction, offline cross-version access, and the idempotent data migration; confirm they fail for the intended missing behavior.

## 2. License consistency

- [ ] 2.1 Replace `crmeb/LICENSE.txt` and the installer agreement with Apache-2.0-compatible text without removing upstream copyright or author attribution.
- [ ] 2.2 Apply an exact, allow-listed mechanical replacement of the restrictive header line in project-owned source; do not rewrite `vendor/` or third-party license files.
- [ ] 2.3 Regenerate affected file-integrity records from the final file bytes and verify every retained manifest entry matches its file.

## 3. Remove commercial authorization

- [ ] 3.1 Delete `SystemAuthServices`, `Common::auth()`, `Common::auth_apply()`, and the `check_auth`, `auth_apply`, and `auth` routes.
- [ ] 3.2 Remove the management-dashboard authorization request, authorization API wrappers, purchase iframe, authorization state, price, order, and application code.
- [ ] 3.3 Rename the retained diagnostics page and route to `system/info`, keep a local site-branding editor, update component/API symbols, and migrate menu ID `605` in place.
- [ ] 3.4 Delete the unreferenced legacy upgrade client and services that synchronize site information or check domain authorization, then remove their inventory entries.

## 4. Neutral site branding

- [ ] 4.1 Migrate backend configuration keys and consumers to `site_copyright_text` and `site_copyright_image` while preserving the `/copyright` URL and response fields.
- [ ] 4.2 Rename management API symbols to neutral site-branding names and remove CRMEB text, links, and image fallbacks from admin, login, customer-service, PC, H5, and mini-program source.
- [ ] 4.3 Remove or replace assets used only as CRMEB fallbacks after proving they have no remaining business references.
- [ ] 4.4 Verify configured branding renders on every consumer and empty/failed branding renders no CRMEB fallback.

## 5. Optional official upgrade

- [ ] 5.1 Add the default-false deployment flag and local status endpoint, with HTTPS official URLs read from configuration.
- [ ] 5.2 Make `UpgradeServices` construction network-free and gate every remote list, agreement, download, status, authentication, and log method before HTTP access.
- [ ] 5.3 Update the online-upgrade page to read local status first and avoid all remote calls while disabled.
- [ ] 5.4 Keep cross-version check, SQL preview, backup, execute, progress, and rollback paths independent from the remote gate and verify them offline.

## 6. Installer and existing database

- [ ] 6.1 Remove authorization API documentation, authorization-only translations, deleted-file inventory rows, and old menu values from the fresh-install SQL and embedded upgrade manifest.
- [ ] 6.2 Add a transactional, prefix-aware, idempotent migration that removes only authorization metadata, migrates the two branding keys, and updates menu ID `605`.
- [ ] 6.3 Run the migration twice against fixtures and the approved local database; verify the second run is a no-op and product/order/user/upload/example-data evidence is unchanged.

## 7. Build and verification

- [ ] 7.1 Run focused compliance, PHP syntax, backend, migration, and official-upgrade gate tests.
- [ ] 7.2 Build the management client, synchronize its manifest-referenced output to `crmeb/public/admin`, and remove only stale unreferenced hashed assets.
- [ ] 7.3 Build every reproducible H5/mini-program target and record any target that cannot be built with the repository toolchain.
- [ ] 7.4 Log in locally and verify dashboard, system information, branding present/empty states, online-upgrade disabled state, and offline cross-version workflows while recording network requests.
- [ ] 7.5 Run the strict source/published-asset denylist, existing runtime acceptance including `/copyright`, and data/upload snapshot comparison.
- [ ] 7.6 Run `openspec validate align-apache-commercial-distribution`, review the final diff, and confirm `.env`, `.constant`, `.codex/`, credentials, business data, uploads, and unrelated dirty files are absent from the change.
