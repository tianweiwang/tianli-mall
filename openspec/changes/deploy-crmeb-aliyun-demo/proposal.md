## Why

The verified PHP 8.2 CRMEB build needs an isolated Aliyun demonstration deployment that preserves the existing static franchise Demo and does not expose MySQL, Redis, or PHP-FPM.

## What Changes

- Add a server-only Compose definition that loads prebuilt application images and publishes only port 8011.
- Persist MySQL, Redis, runtime, and uploaded files below one deployment directory.
- Initialize a new demonstration database from the authoritative sample SQL on first startup.
- Document the no-domain, demonstration-only deployment and its resource boundary.

## Capabilities

### New Capabilities

- `aliyun-crmeb-demo-deployment`: Repeatable isolated deployment of the PHP 8.2 mall for demonstration use.

## Impact

- Affected files: `help/docker/docker-compose.demo-server.yml`, its environment template, Docker build declarations, deployment documentation, and this OpenSpec change.
- Remote impact: install Docker on the designated Ubuntu server, publish TCP 8011, and create persistent state under `/srv/tianli/crmeb-demo`.
- Out of scope: domain names, TLS, production payments, real customer data, and production disaster recovery.
