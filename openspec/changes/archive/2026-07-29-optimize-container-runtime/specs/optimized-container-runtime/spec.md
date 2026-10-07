## ADDED Requirements

### Requirement: Image-backed application runtime
The optimized runtime SHALL serve application code and Composer dependencies from Docker image layers rather than a Windows bind mount.

#### Scenario: Optimized containers start
- **WHEN** the optimized Compose stack is built and started
- **THEN** `/var/www/vendor` and application source are present in the PHP image and are not host bind mounts
- **THEN** Nginx serves its public assets from its own image

#### Scenario: Source code changes
- **WHEN** a PHP or bundled public file changes on the host
- **THEN** the runtime SHALL require an image rebuild before that change is served

#### Scenario: Clean checkout starts
- **WHEN** the optimized images are built from a clean checkout
- **THEN** the PHP image SHALL contain `public/install.lock`
- **THEN** Nginx SHALL reject requests to `/install`

### Requirement: Accelerated PHP bootstrap
The PHP runtime SHALL load OPcache, disable application and database debug behavior, and avoid PHP execution for the `/pages/*` SPA fallback.

#### Scenario: PHP acceleration is active
- **WHEN** the PHP container is healthy
- **THEN** `Zend OPcache` SHALL be loaded and enabled for FPM
- **THEN** application debug and database debug SHALL both be disabled

#### Scenario: Warm dynamic requests
- **WHEN** three consecutive warm requests are made to `/` on the current acceptance host
- **THEN** every request SHALL return HTTP 200 in under 2 seconds

#### Scenario: SPA fallback request
- **WHEN** `/pages/index/index` is requested
- **THEN** Nginx SHALL return the SPA entry document without invoking ThinkPHP

### Requirement: Reliable managed processes
The optimized runtime SHALL keep PHP-FPM, queue, timer, and Workerman under foreground process supervision and SHALL report unhealthy when required listeners are unavailable.

#### Scenario: Clean startup
- **WHEN** the PHP container starts with stale Workerman or timer PID files in its runtime volume
- **THEN** only those known PID files SHALL be removed before process startup
- **THEN** PHP-FPM, queue, timer, and Workerman SHALL report `RUNNING` under Supervisor
- **THEN** PHP-FPM, Workerman ports 40001 and 40002, and internal channel port 40003 SHALL become reachable

#### Scenario: Application dependencies are ready
- **WHEN** the PHP container reports healthy
- **THEN** the mounted application configuration SHALL authenticate successfully to MySQL and Redis

#### Scenario: Container recreation
- **WHEN** the optimized stack is stopped and started again without deleting volumes
- **THEN** Workerman SHALL start successfully without an `already running` error
- **THEN** `/notice` and `/msg` SHALL no longer return HTTP 502

### Requirement: Acceptance data preservation
The runtime migration SHALL preserve the existing local MySQL records and uploaded files.

#### Scenario: Migration completes
- **WHEN** the legacy stack is replaced by the optimized stack
- **THEN** the database SHALL still contain the recorded baseline counts, including 4 products and 2 users
- **THEN** the recorded upload file count SHALL remain unchanged
- **THEN** the PHP runtime user SHALL be able to write to the mounted uploads directory

#### Scenario: Runtime rollback
- **WHEN** optimized runtime verification fails
- **THEN** operators SHALL be able to stop it without deleting volumes and restart the legacy Compose stack against the same database and uploads

### Requirement: Internal service isolation
The optimized acceptance stack SHALL publish only the Nginx HTTP port to the host.

#### Scenario: Compose port inspection
- **WHEN** the optimized containers are running
- **THEN** MySQL, Redis, PHP-FPM, and Workerman SHALL be reachable only on the Compose network
- **THEN** host port 8011 SHALL provide the storefront entrypoint
