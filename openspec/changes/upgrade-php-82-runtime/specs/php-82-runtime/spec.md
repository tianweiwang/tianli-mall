## ADDED Requirements

### Requirement: PHP 8.2 application runtime
The optimized runtime SHALL execute the application on the PHP 8.2 release line with the extension capabilities the PHP 7.4 image provided.

#### Scenario: Runtime reports its version
- **WHEN** the PHP container is healthy
- **THEN** the running PHP version SHALL be in the 8.2 line
- **THEN** `bcmath`, `curl`, `fileinfo`, `gd`, `iconv`, `mbstring`, `mysqli`, `pdo_mysql`, `pcntl`, `redis`, `simplexml`, `sockets`, `sodium`, `xml`, and `zip` SHALL be loaded
- **THEN** `Zend OPcache` SHALL be enabled and `opcache.jit` SHALL be disabled

#### Scenario: Process supervision survives the base image change
- **WHEN** the PHP image is built from the official PHP 8.2 base
- **THEN** Supervisor SHALL be installed in the image
- **THEN** PHP-FPM, queue, timer, and Workerman SHALL report `RUNNING` with restart counts at zero

#### Scenario: Web entrypoint version gate
- **WHEN** the entrypoint runs on PHP 8.2
- **THEN** it SHALL serve the application
- **WHEN** the entrypoint runs on an unvalidated release line
- **THEN** it SHALL refuse to start and name the actual supported range

### Requirement: Platform-checked dependency set
The committed dependency configuration SHALL require the PHP 8.2 line and pass platform verification without a bypass.

#### Scenario: Dependency compatibility check
- **WHEN** `composer prohibits php 8.2 --locked` runs against the committed lock
- **THEN** it SHALL report no package requiring an incompatible PHP version

#### Scenario: Platform requirements check
- **WHEN** `composer check-platform-reqs` runs against the installed tree
- **THEN** every entry SHALL succeed
- **THEN** no `--ignore-platform-reqs`, advisory-ignore, or extension-ignore setting SHALL be present in committed configuration

#### Scenario: Resolution stays within the reviewed delta
- **WHEN** the targeted lock update is applied
- **THEN** exactly the reviewed 10 updates and 3 additions SHALL differ from the prior lock
- **THEN** no package SHALL be removed
- **THEN** any other movement SHALL stop the change for review

#### Scenario: Advisory posture does not regress
- **WHEN** dependency audits are compared before and after the update
- **THEN** the advisory count and severity SHALL NOT increase

### Requirement: Trustworthy dependency baseline
Compatibility measurements SHALL be taken against a Composer tree that matches the lock file.

#### Scenario: Installed tree matches the lock
- **WHEN** the baseline is established
- **THEN** the unchanged lock SHALL have been installed into an empty vendor directory under PHP 7.4 or an explicitly isolated diagnostic PHP-platform bypass
- **THEN** installed package versions, `installed.json`, and `composer.lock` SHALL agree
- **THEN** every recorded install path SHALL exist on disk
- **THEN** source references or Composer metadata SHALL be used for version checks rather than package source constants

#### Scenario: Lint harness does not hide failures
- **WHEN** a tree containing more than one parse failure is linted
- **THEN** every failing file SHALL be reported
- **THEN** a fatal parse error SHALL NOT terminate the remainder of the scan
- **THEN** the verification command SHALL exit nonzero

### Requirement: Application compatibility repairs
Application code SHALL run on PHP 8.2 without parse failures or deprecations, preserving positional call semantics.

#### Scenario: Application code compiles and runs clean
- **WHEN** application code is linted and all autoloadable classes are loaded under `E_ALL`
- **THEN** there SHALL be no parse failures
- **THEN** there SHALL be no deprecations originating in application code

#### Scenario: Parameter declarations are repaired safely
- **WHEN** an optional-before-required declaration is repaired
- **THEN** the ineffective default SHALL be removed rather than the parameters reordered
- **THEN** existing positional callers SHALL retain their meaning

### Requirement: Vendor compatibility overrides
Classes that cannot compile on PHP 8.2 and are reachable at runtime SHALL be replaced from project-owned code without editing `vendor/`.

#### Scenario: Local image upload handling
- **WHEN** a GIF is uploaded through local storage
- **THEN** the request SHALL succeed rather than fail with an unlogged fatal error
- **THEN** thumbnail and watermark output SHALL match the PHP 7.4 baseline byte for byte
- **THEN** an animated GIF SHALL retain all of its frames

#### Scenario: WeChat collection class loads
- **WHEN** the WeChat integration path loads its support collection
- **THEN** the class SHALL compile on PHP 8.2
- **THEN** `__set_state()` SHALL reconstruct a collection without static-context errors
- **THEN** the class SHALL emit no PHP 8.2 interface or serialization deprecations

#### Scenario: Overrides survive dependency installation
- **WHEN** `composer install` runs
- **THEN** the project-owned replacements SHALL still take precedence over the packaged classes
- **THEN** no file under `vendor/` SHALL have been modified by hand

#### Scenario: Strict application startup remains usable
- **WHEN** console and web startup initialize ThinkPHP under `E_ALL`
- **THEN** reachable `think-orm` and `Pimple` interface implementations SHALL emit no PHP 8.2 return-type deprecations
- **THEN** ThinkPHP's error handler SHALL NOT be weakened to suppress deprecations globally

#### Scenario: Image receives a clean dependency tree
- **WHEN** the PHP image is built
- **THEN** Composer SHALL install the committed lock in an isolated build stage
- **THEN** the repository's tracked `vendor/` directory SHALL NOT be copied into the final image

### Requirement: Recorded known issues
Compatibility failures that cannot be repaired within this change SHALL be recorded rather than silently tolerated or treated as release blockers.

#### Scenario: Runtime deprecations are eliminated
- **WHEN** application, project-owned compatibility, and production-startup classes are loaded under `E_ALL`
- **THEN** application code, project-owned compatibility classes, and reachable vendor classes SHALL report zero deprecations
- **THEN** dependency test suites and optional framework adapters SHALL NOT be treated as production runtime classes

#### Scenario: Log scan excludes only recorded sites
- **WHEN** logs are scanned after exercised workflows
- **THEN** there SHALL be no `Fatal error`, `TypeError`, unexpected `Deprecated`, or repeating worker restart
- **THEN** the recorded static-GIF `Undefined array key 0` warning SHALL be the only excluded site

### Requirement: Acceptance data preservation and rollback
The migration SHALL preserve acceptance data and retain a tested PHP 7.4 rollback path.

#### Scenario: Baselines are compared against a snapshot
- **WHEN** database and upload baselines are verified
- **THEN** they SHALL be compared against a snapshot taken before migration rather than hard-coded literals

#### Scenario: Rollback checkpoint exists before cutover
- **WHEN** the PHP 8.2 image is built
- **THEN** the PHP 7.4 image SHALL already be retagged under a distinct rollback tag
- **THEN** restoring that tag SHALL produce a healthy stack against the same database and uploads

#### Scenario: Containers are recreated without data loss
- **WHEN** application containers are recreated for cutover
- **THEN** MySQL data, Redis state, the runtime volume, and uploads SHALL be retained
- **THEN** no schema or data migration SHALL run
