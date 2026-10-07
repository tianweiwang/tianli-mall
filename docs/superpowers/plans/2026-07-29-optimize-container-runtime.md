# Optimized Container Runtime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the slow Windows bind-mounted CRMEB runtime with image-backed PHP/Nginx containers while preserving the local demo database and uploads.

**Architecture:** Build the application into separate PHP and Nginx images. Keep MySQL and uploads on their current bind mounts, move runtime/cache/PID state to a Linux named volume, supervise all PHP processes in the foreground, and gate startup with health checks.

**Tech Stack:** Docker Compose, PHP 7.4 transitional runtime, Zend OPcache, Supervisor, Nginx, MySQL 8, Redis, PowerShell verification.

---

### Task 1: Write the failing runtime verification

**Files:**
- Create: `help/docker/verify-optimized.ps1`

- [ ] **Step 1: Add assertions for the requested behavior**

The script must fail unless all of these are true: OPcache is loaded, `/var/www` is not a host bind mount, only Nginx publishes a host port, PHP/Workerman listeners are reachable, three warm `/` requests complete below 2 seconds, `/notice` and `/msg` are not 502, and database/upload counts match `4/2/174`.

```powershell
param(
    [string]$ComposeFile = "help/docker/docker-compose.optimized.yml",
    [string]$EnvFile = "help/docker/.env.runtime",
    [int]$ExpectedProducts = 4,
    [int]$ExpectedUsers = 2,
    [int]$ExpectedUploads = 174
)
```

- [ ] **Step 2: Run the script against the legacy stack**

Run:

```powershell
pwsh -File help/docker/verify-optimized.ps1 -ComposeFile help/docker/docker-compose.yml
```

Expected: non-zero exit with explicit failures for OPcache, bind-mounted source, published internal ports, latency, and Workerman listeners.

### Task 2: Build the immutable PHP runtime

**Files:**
- Modify: `.dockerignore`
- Create: `docker/php/Dockerfile`
- Create: `docker/php/opcache.ini`
- Create: `docker/php/supervisord.conf`
- Create: `docker/php/runtime-entrypoint.sh`
- Create: `docker/php/runtime-healthcheck.php`
- Modify: `crmeb/crmeb/command/Timer.php`

- [ ] **Step 1: Exclude mutable and installation-specific state**

Add these exact exclusions:

```text
crmeb/runtime/
crmeb/public/uploads/
crmeb/public/install/
crmeb/.env
crmeb/.constant
```

- [ ] **Step 2: Create the derived PHP image**

The Dockerfile must install the bundled OPcache extension, copy configuration, copy `crmeb/` as root-owned code, create writable runtime/uploads directories owned by `www-data`, and retain the base image's PHP entrypoint behavior.

```dockerfile
FROM ccr.ccs.tencentyun.com/crmebky_php/php:v7.4
RUN docker-php-ext-install -j"$(nproc)" opcache
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-runtime.ini
COPY docker/php/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/php/runtime-entrypoint.sh /usr/local/bin/runtime-entrypoint
COPY docker/php/runtime-healthcheck.php /usr/local/bin/runtime-healthcheck.php
COPY --chown=root:root crmeb/ /var/www/
RUN mkdir -p /var/www/runtime /var/www/public/uploads \
    && touch /var/www/public/install.lock \
    && chown -R www-data:www-data /var/www/runtime /var/www/public/uploads \
    && chmod 0755 /usr/local/bin/runtime-entrypoint
ENTRYPOINT ["runtime-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
```

- [ ] **Step 3: Configure PHP acceleration**

Required settings: `opcache.enable=1`, `opcache.enable_cli=1`, `opcache.validate_timestamps=0`, `opcache.max_accelerated_files=30000`, `opcache.memory_consumption=256`, `display_errors=Off`, and `log_errors=On`.

- [ ] **Step 4: Configure process supervision**

Supervisor must run `php-fpm --nodaemonize`, `php think queue:listen --queue`, `php think timer start`, and `php think workerman start`. The last three run as `www-data`; no command uses `--d`; every program stops its process group and restarts on unexpected exit. Timer and Workerman logs must use the writable runtime volume.

- [ ] **Step 5: Clean only known stale PID files**

```sh
#!/bin/sh
set -eu
mkdir -p /var/www/runtime /var/www/public/uploads
chown -R www-data:www-data /var/www/runtime /var/www/public/uploads
rm -f /var/www/runtime/workerman.pid /var/www/runtime/timer.pid
exec docker-php-entrypoint "$@"
```

### Task 3: Build the immutable Nginx runtime

**Files:**
- Create: `docker/nginx/Dockerfile`
- Create: `docker/nginx/default.conf`

- [ ] **Step 1: Copy public assets into the Nginx image**

```dockerfile
FROM ccr.ccs.tencentyun.com/crmebky_php/nginx:v1.29.6
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY crmeb/public/ /var/www/public/
```

- [ ] **Step 2: Configure routing**

The server listens on port 80 with `server_name _`. `/install` returns 404, `/pages/*` falls back directly to `/index.html`, and other missing routes fall back to `/index.php`; PHP uses `fastcgi_pass phpfpm:9000` and `try_files $uri =404`; `/notice` and `/msg` proxy to `phpfpm:40001` and `phpfpm:40002` using WebSocket headers.

- [ ] **Step 3: Validate Nginx syntax in the built image**

Run:

```powershell
docker exec crmeb_nginx nginx -t
```

Expected: `syntax is ok` and `test is successful`.

### Task 4: Add the optimized Compose stack

**Files:**
- Modify: `.gitignore`
- Create: `help/docker/.env.runtime.example`
- Create locally and ignore: `help/docker/.env.runtime`
- Create: `help/docker/docker-compose.optimized.yml`

- [ ] **Step 1: Define local runtime variables**

The example contains `MYSQL_ROOT_PASSWORD`, `MYSQL_DATABASE=crmeb`, `MYSQL_USER=crmeb`, `MYSQL_PASSWORD`, and `REDIS_PASSWORD`. The local ignored file uses credentials matching the preserved acceptance database.

- [ ] **Step 2: Define services and state boundaries**

The optimized file builds PHP/Nginx from the repository root, reuses `./mysql/data` and `../../crmeb/public/uploads`, mounts `.env` and `.constant` read-only, creates named `runtime_data` and `redis_data` volumes, applies the runtime Redis password, writes the Redis PID under `/data`, reduces Redis logging to `notice`, and publishes only `8011:80`.

- [ ] **Step 3: Add health checks**

MySQL uses authenticated `mysqladmin ping`; Redis uses authenticated `redis-cli ping`; PHP passively checks listening ports `9000`, `40001`, `40002`, and `40003` and connects to MySQL/Redis with the mounted application configuration; Nginx fetches `/favicon.ico`. Dependencies use `condition: service_healthy`.

- [ ] **Step 4: Validate Compose before migration**

Run:

```powershell
docker compose --env-file help/docker/.env.runtime -f help/docker/docker-compose.optimized.yml config --quiet
```

Expected: exit 0 with no interpolation or schema errors.

### Task 5: Build, migrate, and verify

**Files:**
- Modify: `help/docker/README.md`
- Modify: `openspec/changes/optimize-container-runtime/tasks.md`

- [ ] **Step 1: Record state before stopping containers**

Run database counts for tables, products, users, DIY pages, group data, and language data; count all upload files. Expected current baselines are `157/4/2/6/92/25210/174`.

- [ ] **Step 2: Build before downtime**

```powershell
docker compose --env-file help/docker/.env.runtime -f help/docker/docker-compose.optimized.yml build
```

- [ ] **Step 3: Switch stacks without deleting volumes**

```powershell
docker compose -f help/docker/docker-compose.yml down
docker compose --env-file help/docker/.env.runtime -f help/docker/docker-compose.optimized.yml up -d
```

Never add `-v` to either command.

- [ ] **Step 4: Verify the optimized runtime**

```powershell
pwsh -File help/docker/verify-optimized.ps1
openspec validate optimize-container-runtime
codegraph sync
```

Expected: all runtime assertions pass, OpenSpec validates, and CodeGraph is current.

- [ ] **Step 5: Verify restart behavior**

Run `down` and `up -d` again without `-v`, then repeat the verification script. Expected: Workerman is healthy, no stale-PID error appears, and all demo-data counts remain unchanged.

- [ ] **Step 6: Document rollback**

The README must state that rollback is `optimized down` followed by legacy `up -d`, both without `-v`, and that this stack is acceptance-only until the PHP 8.2 and production-security changes are complete.
