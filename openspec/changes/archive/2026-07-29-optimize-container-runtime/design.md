## Context

The acceptance stack currently mounts `crmeb/` from a Windows `H:` drive at `/var/www`. Docker reports that mount as `9p`; loading Composer from it takes 3.6-4.1 seconds, while the same files on the container filesystem load in about 2.8 milliseconds. A complete dynamic request consequently takes 10-17 seconds even though MySQL and Redis operations complete in milliseconds.

The vendor PHP 7.4 image also lacks OPcache and starts Workerman with `--d` under Supervisor. Workerman detaches, Supervisor considers it finished, and stale PID files in the persisted runtime directory cause later starts to report `already running`; Nginx therefore returns 502 for `/notice` and `/msg`.

The current local database and uploads contain acceptance demo data that must survive the runtime migration. PHP 8.2 is not viable in this change because the locked dependency set contains ten PHP-7-only packages and the application entrypoint rejects PHP 8.

## Goals / Non-Goals

**Goals:**

- Serve PHP code and Composer dependencies from image layers rather than a Windows bind mount.
- Enable OPcache and disable runtime debug behavior.
- Keep PHP-FPM, queue, timer, and Workerman alive under one foreground Supervisor for this transitional local stack.
- Make container readiness reflect HTTP, database, cache, FPM, and Workerman availability.
- Preserve the existing local MySQL data and uploaded assets.
- Provide a low-risk rollback to the untouched legacy Compose file.

**Non-Goals:**

- PHP 8.2 dependency migration.
- Public-production TLS, Certbot, off-host backups, secret rotation, or object storage.
- Dangerous admin-feature and anonymous upgrade-route hardening.
- Database index migrations.
- Horizontal scaling or final production FPM sizing.

## Decisions

### Build application code into two images

The optimized Compose file will build a PHP image containing the complete `crmeb/` application and a separate Nginx image containing `crmeb/public/`. PHP and Nginx therefore read application files from Docker's Linux filesystem. The alternative of moving the checkout into WSL2 would improve this one workstation but would not create a repeatable deployment artifact.

The root directory remains the Docker build context. `.dockerignore` will exclude runtime state, uploads, the web installer, and installation-specific `.env`/`.constant` files while retaining `vendor`, because the current lock file and dependencies must remain unchanged in this change. The PHP image creates `public/install.lock` explicitly so a clean checkout cannot fall back to installation mode, and Nginx rejects `/install` requests.

### Preserve only mutable state

The existing `help/docker/mysql/data` directory and `crmeb/public/uploads` directory remain mounted so the demo database and files survive. `runtime` moves to a Docker named volume so cache, logs, and PID access use a Linux filesystem. The PHP entrypoint normalizes ownership of runtime and uploads after volumes are mounted so `www-data` can write on Linux hosts. `.env` and `.constant` remain explicit read-only mounts and are not copied into the image.

### Use a transitional PHP 7.4 image with OPcache

The PHP image derives from the current vendor image to preserve its extension set, installs the bundled OPcache extension, and adds a production-style INI file. OPcache timestamp validation is disabled because code changes arrive only through image rebuilds. This choice fixes the measured bottleneck now but does not make PHP 7.4 an acceptable public-production target.

### Keep Supervisor, but supervise foreground processes correctly

For the acceptance stack, PHP-FPM, queue, timer, and Workerman stay in one container to limit the first migration's blast radius. Supervisor runs every program in the foreground, restarts unexpected exits, stops process groups cleanly, and runs queue/timer/Workerman as `www-data`. Workerman and timer logs are directed to the writable runtime volume rather than the immutable vendor tree. A small entrypoint removes only known stale `runtime/workerman.pid` and `runtime/timer.pid` files before Supervisor starts.

Splitting the four processes into separate services remains the production design, but doing it together with the filesystem migration would make rollback and root-cause attribution harder.

### Gate startup and route by service name

Compose health checks cover MySQL, authenticated Redis, PHP-FPM, both Workerman WebSocket ports, the internal Workerman channel port, and Nginx HTTP. PHP readiness also connects to MySQL and Redis using the mounted application configuration, so mismatched runtime and application credentials cannot report healthy. Redis receives its password from the local runtime environment, writes its PID into the persistent `/data` directory instead of the image's unwritable `/var/run` default, and uses `notice` logging instead of verbose per-connection output. Nginx proxies WebSocket traffic to the PHP service name instead of a fixed IP. Internal MySQL, Redis, FPM, and Workerman ports are exposed only on the Compose network; only port 8011 is published locally.

Nginx serves `/pages/*` through the built-in SPA fallback without booting ThinkPHP. Other missing paths continue to use the existing PHP routing semantics.

### Add an optimized Compose file rather than replacing legacy startup

`help/docker/docker-compose.optimized.yml` becomes the acceptance command. The legacy `docker-compose.yml` remains unchanged for immediate rollback. Both files point to the same MySQL data and uploads, but they are never run simultaneously because they use the same container names and network.

## Risks / Trade-offs

- [Image builds initially read thousands of files from Windows and may be slow] -> Build once, rely on layer cache, and verify runtime latency after the image is created.
- [Image-layer code does not update live] -> Document and verify the explicit rebuild command.
- [Named runtime volume starts without prior cache/log history] -> Preserve the old host runtime directory untouched; only stateless cache, logs, and stale process metadata move.
- [PHP 7.4 remains end-of-life] -> Label the stack acceptance-only and keep PHP 8.2 as a release blocker.
- [Public files exist in both images] -> Build both from the same checkout and verify their image labels/source revision in later production work.
- [Host upload permissions differ from Linux production] -> Verify upload readability locally; define final Linux ownership in the production hardening change.

## Migration Plan

1. Record table, product, user, DIY-page, group-data, language-data, and upload-file baselines.
2. Build the PHP and Nginx images while the legacy stack remains available.
3. Stop the legacy stack without `-v` and without deleting bind-mounted data.
4. Start `docker-compose.optimized.yml` with the local runtime environment file.
5. Wait for all health checks, then verify HTTP latency, `/notice`, `/msg`, Redis, MySQL, and demo-data counts.
6. If verification fails, stop the optimized stack without `-v` and restart the untouched legacy Compose file.

## Open Questions

None for the local acceptance migration. Domain, certificate email, production upload storage, secret rotation, and PHP 8.2 package choices remain inputs to later production changes.
