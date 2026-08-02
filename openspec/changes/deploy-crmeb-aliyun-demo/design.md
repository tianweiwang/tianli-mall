## Decisions

### Load prebuilt application images

The server loads the locally verified PHP and Nginx image archive instead of building source or resolving Docker Hub build frontends. MySQL and Redis use the existing pinned runtime images.

### Keep all mutable state in one deployment directory

MySQL, Redis, runtime, uploads, initialization SQL, and uncommitted secrets live below `/srv/tianli/crmeb-demo`. Compose teardown must never use `-v`, and redeployment must not clear these paths.

### Publish one demonstration port

Only Nginx publishes `${HTTP_PORT:-8011}`. MySQL, Redis, and PHP-FPM remain reachable only on the Compose network. The existing host Nginx and `/franchise-demo/` release are unchanged.

The redeploy command restarts Nginx after Compose updates application containers so its resolved PHP upstream cannot retain a removed container IP.

The redeploy command keeps the mounted application configuration at `root:www-data` mode `0640`. The PHP healthcheck runs as `www-data`, matching PHP-FPM workers, so a root-only configuration cannot produce a false healthy state.

### Initialize from authoritative sample data

The first MySQL startup imports the verified complete sample SQL. The matching upload archive is restored before application startup. No current local verification mutations are copied.

MySQL health requires a persistent completion marker written by the final initialization script. A temporary initialization server accepting connections is not sufficient to release PHP-FPM.

### Bound memory on the small server

Service memory limits plus host swap keep the four-container stack usable on the 1.6 GiB demonstration server. These limits are demonstration settings, not production capacity guidance.

## Verification

- Validate the Compose model with generated non-placeholder secrets.
- Require healthy MySQL, Redis, PHP, and Nginx containers.
- Verify PHP 8.2 runtime facts, Supervisor process stability, HTTP routes, business-successful bootstrap JSON, WebSocket upgrades, and upload I/O.
- Verify that the management upgrade capability remains locally available and reports remote upgrade disabled without external requests.
- Verify empty content-filter configuration and Workerman timer scheduling under the restored sample data.
- Verify the restored database and uploads against the authoritative acceptance snapshot.
- Verify external storefront and admin responses on port 8011 while the existing H5 Demo remains HTTP 200.
