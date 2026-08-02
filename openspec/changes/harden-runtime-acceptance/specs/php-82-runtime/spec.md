## MODIFIED Requirements

### Requirement: PHP 8.2 application runtime
The optimized runtime SHALL execute the application on the PHP 8.2 release line with the extension capabilities the PHP 7.4 image provided, and acceptance SHALL inspect those facts inside the deployed container.

#### Scenario: Runtime reports its version
- **WHEN** the deployed PHP container is healthy
- **THEN** the running PHP version SHALL be in the 8.2 line
- **THEN** `bcmath`, `curl`, `fileinfo`, `gd`, `iconv`, `mbstring`, `mysqli`, `pdo_mysql`, `pcntl`, `redis`, `simplexml`, `sockets`, `sodium`, `xml`, and `zip` SHALL be loaded
- **THEN** `Zend OPcache` SHALL be enabled and `opcache.jit` SHALL be disabled

#### Scenario: Process supervision survives the base image change
- **WHEN** the deployed PHP container is sampled over a bounded interval
- **THEN** PHP-FPM, queue, timer, and Workerman SHALL report `RUNNING` in both samples
- **THEN** their process identities SHALL remain stable and restart evidence SHALL remain zero

#### Scenario: Web entrypoint version gate
- **WHEN** the entrypoint runs on PHP 8.2
- **THEN** it SHALL serve the application
- **WHEN** the entrypoint runs on an unvalidated release line
- **THEN** it SHALL refuse to start and name the actual supported range

#### Scenario: Storefront bootstrap handles typed runtime values
- **WHEN** an unset system configuration value is read on PHP 8.2
- **THEN** the configured default SHALL be returned without a null-to-string deprecation
- **WHEN** ThinkORM renders SQL containing integer bound values for diagnostics
- **THEN** it SHALL preserve the SQL value semantics without passing non-string replacements to `substr_replace()`
- **THEN** the repair SHALL live in project-owned code and the Composer lock SHALL remain unchanged

#### Scenario: ORM collections load without terminating managed workers
- **WHEN** a managed process first converts an ORM result set to `think\Collection` under `E_ALL`
- **THEN** the collection SHALL satisfy the PHP 8.2 interface return-type contracts without emitting a deprecation
- **THEN** the managed process SHALL remain running
- **THEN** the repair SHALL use project-owned compatibility code without modifying `vendor/` or the Composer lock

#### Scenario: Default product list does not activate an empty coupon filter
- **WHEN** the storefront requests products without selecting a coupon category and the normalized category value is integer zero
- **THEN** product list and count queries SHALL omit the coupon-category subquery
- **THEN** otherwise eligible products SHALL remain visible on PHP 8.2

### Requirement: Recorded known issues
Compatibility failures that cannot be repaired within this change SHALL be recorded rather than silently tolerated or hidden by the acceptance gate.

#### Scenario: Runtime deprecations are eliminated
- **WHEN** application, project-owned compatibility, and production-startup classes are loaded under `E_ALL`
- **THEN** application code, project-owned compatibility classes, and reachable vendor classes SHALL report zero deprecations
- **THEN** dependency test suites and optional framework adapters SHALL NOT be treated as production runtime classes

#### Scenario: Log scan excludes only recorded sites
- **WHEN** logs produced after representative workflows are scanned
- **THEN** there SHALL be no `Fatal error`, `TypeError`, unexpected `Deprecated`, process termination, or repeating worker restart
- **THEN** the recorded static-GIF `Undefined array key 0` warning SHALL be the only excluded site

### Requirement: Acceptance data preservation and rollback
The migration SHALL preserve acceptance data and retain a tested PHP 7.4 rollback path.

#### Scenario: Baselines are compared against a snapshot
- **WHEN** database and upload baselines are verified
- **THEN** normalized database schema and data digests SHALL match an explicit snapshot taken before migration
- **THEN** every upload path, size, and SHA256 digest SHALL match that snapshot as a complete set
- **THEN** verification SHALL NOT use hard-coded record or file counts as integrity evidence

#### Scenario: Rollback checkpoint exists before cutover
- **WHEN** the PHP 8.2 image is built
- **THEN** the PHP 7.4 image SHALL already be retagged under a distinct rollback tag
- **THEN** restoring that tag SHALL produce a healthy stack against the same database and uploads

#### Scenario: Containers are recreated without data loss
- **WHEN** application containers are recreated for cutover
- **THEN** MySQL data, Redis state, the runtime volume, and uploads SHALL be retained
- **THEN** no schema or data migration SHALL run
