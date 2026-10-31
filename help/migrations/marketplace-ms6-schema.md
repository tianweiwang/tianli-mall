# MS6 schema compatibility metadata

This is the first compatibility generation for the generic multi-merchant application: product `generic-multi-merchant`, application `0.1.0`, schema `1`. It is not a release or milestone acceptance declaration.

Only `<controlled-prefix>marketplace_schema` is created. Its exact four columns and enforced singleton constraints are defined by `SchemaVersionGate::ddl()`. The application reads this table and never creates, updates, lowers or drops its metadata. All marketplace feature flags remain unchanged; disabled features do not bypass the version gate.

## Explicit maintenance bootstrap

Use a qualified source checkout containing the candidate manifest/classes and the original successful prerequisites. Preserve the actual schema, all existing business data, files and Redis before and after. The following are command forms, not evidence that they have been executed:

```sh
php help/migrations/marketplace-ms6-schema.php help
php help/migrations/marketplace-ms6-schema.php check --repo-root=/workspace --config-file=/private-ms6/config.json
php help/migrations/marketplace-ms6-schema.php apply --repo-root=/workspace --config-file=/private-ms6/config.json
php help/migrations/marketplace-ms6-schema.php check --repo-root=/workspace --config-file=/private-ms6/config.json
```

Omitting the mode means `check`. `help` does not load the application or connect. `--repo-root` is an explicit canonical checkout path. A private config has exactly `hostname`, `hostport`, `database`, `username`, `password`, `prefix`; the file is a regular single-link file owned by the current POSIX UID with mode `0600`, inside its `0700` directory. It is never committed or printed. Use a dedicated migration account with permission only for this operation; do not grant application accounts DDL privileges.

Without `--config-file`, the checker loads the official application Env and `config/database.php` without App initialization, uses the existing CompatDb lazy cache binding and obtains the real primary PDO via an explicit master read. Only this fresh standalone App's connection configuration copy sets `trigger_sql=false`: the official CONNECT listener includes a DSN and requires logging configuration, so the standalone checker does not activate that listener. Driver, primary selection and all other options are unchanged. This form does not provide missing DDL rights to the application account. Direct application startup uses its already loaded configuration and retains its original logging behavior; no general claim of zero framework file side effects is made.

`check` is read-only. Missing metadata or incompatibility returns a fixed rejection and nonzero exit. `apply` takes a database/prefix-specific named lock, rechecks, then only creates an absent table and inserts its independently constructed initial row. Existing exact initial metadata is unchanged, including `updated_at`. An empty/partial table, heterogeneous structure, another schema/floor or a higher version is refused without overwrite. There is no `ALTER`, downgrade, `DROP`, `TRUNCATE`, historical migration, seed, business update, Redis operation, cache invalidation or automatic restore.

### Refund prerequisite for the current candidate

The shared startup gate now also reads the exact `marketplace_refund_attempt` structure and the installed `marketplace-aftersales-test-refund` operation leaf, including its original refund-page parent chain. This is a concrete prerequisite check; application `0.1.0`, schema generation `1`, and the original four-field schema result remain unchanged. It neither requires an existing role to have this permission nor activates testing refunds or real funds. The application image contains the shared `RefundSchemaReadiness` class and does not load maintenance migrations.

Before this candidate's metadata `apply`/`check`, application startup, or deployment pre-up check, complete the original explicit refund maintenance sequence on the target database, after its original payment/aftersale prerequisites. Use the dedicated migration identity and the same private configuration rules as the original MS5 refund CLI:

```sh
php help/migrations/marketplace-ms5-refund.php check --config-file=/private-ms5/config.json
php help/migrations/marketplace-ms5-refund.php apply-structure --config-file=/private-ms5/config.json
php help/migrations/marketplace-ms5-refund.php verify --config-file=/private-ms5/config.json
php help/migrations/marketplace-ms5-refund.php check-menus --config-file=/private-ms5/config.json
php help/migrations/marketplace-ms5-refund.php apply-menus --config-file=/private-ms5/config.json
php help/migrations/marketplace-ms5-refund.php check-menus --config-file=/private-ms5/config.json
```

Require the final structure and menu receipts to be `completed` with no issues. Initial `check` or `check-menus` may return `applicable` with exit zero; that is not completed readiness. Preserve existing table contents, orders, payments, refunds, inventory, files and roles. Do not rerun preview seed on an existing instance. Structure creation and insertion of the single ungranted operation leaf remain explicit maintenance actions; startup, health and pre-up only perform reads, including when the caller already has a read-only transaction. Application accounts retain DML-only privileges.

The independently approved pre-up checker image must contain this candidate's gate and `RefundSchemaReadiness` implementation. The pre-up receipt and deployment consumer bind all three exact source files (`SchemaVersionGate`, `SchemaVersionRuntime`, `RefundSchemaReadiness`); an older checker receipt is insufficient. These source changes do not constitute a built/verified release image or authorize production deployment.

MySQL DDL is not transactional. If creation or commit is ambiguous, the safe receipt reports `changed:null`; preserve that state for explicit investigation. A later call must not automatically repair or claim success for a partial table.

## Startup behavior

The canonical four-key data manifest has fixed product/version/range and cannot be overridden by environment variables or the legacy `.version`. Schema absence, an unreadable/invalid manifest, incompatible database metadata or an unavailable primary connection prevents business initialization. HTTP receives only a fixed error enum in JSON with HTTP503/no-store; console startup explicitly exits2. Other exception handlers remain unchanged.

Only the actual root `think` entry and the exact no-extra-option `service:discover` / `vendor:publish` argument forms may avoid the database read, after matching the official command mapping. They only perform the existing offline metadata file operations. There is no test, environment or feature-switch bypass for business commands.

## Remaining delivery and verification

The integrated container entrypoint invokes the standalone gate before its original runtime-directory mutations; the complete container healthcheck retains the four listener, MySQL and Redis checks and includes the same schema gate. This source integration does not supply a qualified image/build/public-copy/target database/Run identity or prove actual image startup, pre-up deployment or runbook checks. A new same-source ordinary Host must apply metadata after the original prerequisites and before new App boot. Frozen old Runs/profiles remain unchanged.

Source review and syntax checks alone do not prove a real migration or normal business run. The isolated preview has now completed normal initial metadata creation before first App, native maintenance CLI repeated apply/default check, DML App initialization, three real HTTP logins and schema-aware health. This evidence is limited to the new preview; complete production image/startup/health and stage verification remain open. Stopped negative, fault, concurrent, recovery and K samples remain NOT RUN. R01–R06, full M/A/B/UI, staging and manual release acceptance remain open. Q2 tracking is unchanged; Q3 still blocks real release, with no actual credential rotation performed here.

## Isolated preview initialization

For a new dedicated preview, the existing maintenance bootstrap applies/checks this metadata after the original installation and migrations, before its first App initialization. It also checks the same row using the ordinary DML account. The original 157-table installation assertion, synthetic fixtures, password initialization and file-checksum setup remain in their existing order. The application never receives DDL privileges.

The preview health endpoint checks its sentinel, applies the same environment configuration as normal App startup, resolves the actual primary connection through the original standalone Runtime, then returns the checked schema row. It does not skip the version gate when marketplace features are disabled. Preview services use their existing split processes and paths; preview health is not evidence of the production image's complete single-container startup and health checks.
