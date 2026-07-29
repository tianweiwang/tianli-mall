## Why

The current Windows acceptance environment bind-mounts the complete PHP application and `vendor` tree into Docker. Composer bootstrap takes 3.6-4.1 seconds from that mount versus about 2.8 milliseconds from the container filesystem, producing 10-17 second dynamic responses; the same runtime also leaves Workerman stopped while the containers appear healthy.

## What Changes

- Build the application and Composer dependencies into dedicated PHP and Nginx image layers instead of serving source code from a Windows bind mount.
- Enable and tune OPcache, disable application/database debug behavior, and keep only runtime data and uploads writable.
- Run PHP-FPM, queue, timer, and Workerman under reliable foreground process supervision, without persisting stale PID files across container recreation.
- Add MySQL, Redis, PHP, Workerman, and Nginx health checks and use Compose service names rather than hard-coded container IP addresses.
- Preserve the current local MySQL demo database and uploaded files while rebuilding the stateless application containers.
- Keep uploads writable for the PHP runtime and keep the installer disabled in clean image builds.
- Add repeatable response-time, HTTP, WebSocket, container-health, and demo-data verification commands.
- **BREAKING**: PHP source changes require rebuilding the runtime images; they are no longer reflected live through a source bind mount.
- This transitional acceptance runtime remains on PHP 7.4. PHP 8.2 dependency migration and production security hardening are separate changes and remain mandatory before public launch.

## Capabilities

### New Capabilities

- `optimized-container-runtime`: Immutable application images, accelerated PHP bootstrap, reliable background processes, health-gated startup, and local data preservation.

### Modified Capabilities

None.

## Impact

- Affected deployment files: `help/docker/docker-compose.optimized.yml`, PHP/Nginx image definitions under `docker/`, and runtime configuration under `help/docker/`.
- Affected runtime behavior: PHP application delivery, OPcache, Supervisor-managed processes, WebSocket routing, health checks, and writable-directory ownership.
- Preserved state: `help/docker/mysql/data` and `crmeb/public/uploads`; the existing demonstration records must remain available after migration.
- Follow-up work: PHP 8.2 dependency upgrades, unauthenticated upgrade-route removal and dangerous admin-feature hardening, production-only secrets/TLS/backup configuration, and evidence-based database indexes.
