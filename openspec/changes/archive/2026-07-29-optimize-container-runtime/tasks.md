## 1. Baseline and failing verification

- [x] 1.1 Add an optimized-runtime verification script covering Compose health, OPcache, mount type, published ports, HTTP latency, Workerman listeners, and demo-data counts.
- [x] 1.2 Run the verification script against the legacy stack and record the expected failures for image-backed code, OPcache, latency, port isolation, and Workerman.

## 2. Immutable runtime images

- [x] 2.1 Update `.dockerignore` to exclude runtime state, uploads, `.env`, and `.constant` from application image contexts.
- [x] 2.2 Add the PHP runtime Dockerfile, OPcache INI, foreground Supervisor configuration, and stale-PID cleanup entrypoint.
- [x] 2.3 Add the Nginx runtime Dockerfile and site configuration with static SPA fallback and service-name WebSocket proxies.

## 3. Optimized acceptance Compose

- [x] 3.1 Add a tracked runtime environment example and ignore the local runtime environment file.
- [x] 3.2 Add `docker-compose.optimized.yml` with image builds, Linux runtime volume, preserved database/uploads, internal-only service ports, restart policies, and health-gated dependencies.
- [x] 3.3 Validate the optimized Compose model and image configurations before stopping the legacy stack.

## 4. Migration and verification

- [x] 4.1 Capture the current database and upload baselines, then build both optimized images.
- [x] 4.2 Stop the legacy stack without deleting volumes and start the optimized stack against the preserved data.
- [x] 4.3 Run the verification script and confirm three warm HTTP requests are below two seconds, Workerman endpoints are not 502, OPcache is enabled, internal ports are unpublished, and demo data is unchanged.
- [x] 4.4 Recreate the optimized stack once more without deleting volumes and repeat process-health and data-preservation checks.
- [x] 4.5 Verify clean-checkout installer blocking, application-level dependency readiness, and Linux-compatible upload ownership without changing demo data.

## 5. Specification and handoff

- [x] 5.1 Run `openspec validate optimize-container-runtime` and ensure CodeGraph is synchronized.
- [x] 5.2 Document the optimized start, rebuild, verification, and legacy rollback commands in the Docker help README.
