## MODIFIED Requirements

### Requirement: Accelerated PHP bootstrap
The optimized runtime SHALL load OPcache, disable application and database debug behavior, avoid PHP execution for the `/pages/*` SPA fallback, and expose representative local routes to acceptance checks.

#### Scenario: PHP acceleration is active
- **WHEN** the deployed PHP container is healthy
- **THEN** `Zend OPcache` SHALL be loaded and enabled
- **THEN** application debug and database debug SHALL both be disabled

#### Scenario: Warm dynamic requests
- **WHEN** three consecutive warm requests are made to `/` on the current acceptance host
- **THEN** every request SHALL return HTTP 200 in under 2 seconds

#### Scenario: Representative routes
- **WHEN** storefront, admin, `/pages/index/index`, and a representative public API route are requested
- **THEN** every route SHALL return HTTP 200
- **THEN** `/pages/index/index` SHALL be served by the Nginx SPA fallback without invoking ThinkPHP

#### Scenario: Storefront bootstrap reaches application success
- **WHEN** the optimized stack serves the copyright, theme, home-layout, and language bootstrap APIs
- **THEN** every endpoint SHALL return valid JSON with business `status=200`
- **THEN** PHP 8.2 type diagnostics SHALL NOT be converted into HTTP 200 error payloads

### Requirement: Reliable managed processes
The optimized runtime SHALL keep PHP-FPM, queue, timer, and Workerman under foreground process supervision and SHALL reject transiently running or restarting processes.

#### Scenario: Clean startup
- **WHEN** the PHP container starts with stale Workerman or timer PID files in its runtime volume
- **THEN** only those known PID files SHALL be removed before process startup
- **THEN** PHP-FPM, queue, timer, and Workerman SHALL report `RUNNING` under Supervisor
- **THEN** PHP-FPM, Workerman ports 40001 and 40002, and internal channel port 40003 SHALL become reachable

#### Scenario: Linux entrypoint survives a Windows checkout
- **WHEN** the PHP image is built from a Windows working tree
- **THEN** executable Shell entrypoints SHALL retain LF line endings
- **THEN** the container SHALL start without a missing-interpreter error caused by CRLF

#### Scenario: Stable supervised processes
- **WHEN** PHP-FPM, queue, timer, and Workerman are sampled twice over a bounded interval
- **THEN** every process SHALL remain `RUNNING` with the same PID
- **THEN** relevant post-workflow logs SHALL contain no fatal, termination, or repeating-restart evidence
- **THEN** PHP-FPM, Workerman ports 40001 and 40002, and internal channel port 40003 SHALL remain reachable
- **THEN** first-load ORM collection conversion SHALL NOT terminate a queue, timer, or Workerman process

#### Scenario: Application dependencies are ready
- **WHEN** the PHP container reports healthy
- **THEN** the mounted application configuration SHALL authenticate successfully to MySQL and Redis

#### Scenario: Container recreation
- **WHEN** the optimized stack is stopped and started again without deleting volumes
- **THEN** Workerman SHALL start successfully without an `already running` error
- **THEN** valid WebSocket upgrade requests to `/notice` and `/msg` SHALL return HTTP 101

### Requirement: Acceptance data preservation
The runtime migration SHALL preserve the complete local MySQL acceptance database and uploaded files against an explicit pre-cutover snapshot.

#### Scenario: Migration completes
- **WHEN** the legacy stack is replaced by the optimized stack
- **THEN** normalized database schema and data SHA256 digests SHALL match the snapshot
- **THEN** all upload relative paths, sizes, and SHA256 digests SHALL match the snapshot
- **THEN** the PHP runtime user SHALL create, read, and delete a probe file in the mounted uploads directory without leaving an artifact

#### Scenario: Runtime rollback
- **WHEN** optimized runtime verification fails
- **THEN** operators SHALL be able to stop it without deleting volumes and restart the legacy Compose stack against the same database and uploads
