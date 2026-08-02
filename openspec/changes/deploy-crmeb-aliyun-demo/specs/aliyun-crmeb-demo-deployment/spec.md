## ADDED Requirements

### Requirement: Isolated demonstration runtime
The deployment SHALL run the PHP 8.2 mall without replacing the existing static franchise Demo or exposing internal data services.

#### Scenario: Demonstration stack starts
- **WHEN** the server Compose project starts with valid configuration and prebuilt images
- **THEN** MySQL, Redis, PHP-FPM, and Nginx SHALL become healthy
- **THEN** only Nginx SHALL publish TCP port 8011
- **THEN** MySQL, Redis, and PHP-FPM SHALL remain internal to the Compose network
- **THEN** the application configuration SHALL be readable by PHP-FPM workers without being world-readable
- **THEN** the PHP healthcheck SHALL execute with the same file-read boundary as PHP-FPM workers

### Requirement: Deterministic demonstration data
The deployment SHALL initialize from the authoritative sample database and upload snapshot rather than the locally mutated acceptance database.

#### Scenario: First database initialization
- **WHEN** the MySQL data directory is empty on first startup
- **THEN** the complete sample SQL SHALL be imported once
- **THEN** MySQL SHALL become healthy only after the final initialization script writes a persistent completion marker
- **THEN** all sample upload paths, sizes, and hashes SHALL match the selected snapshot

#### Scenario: Containers are recreated
- **WHEN** application containers are updated or recreated
- **THEN** MySQL, Redis, runtime, and upload state SHALL remain present
- **THEN** initialization SQL SHALL NOT overwrite an existing database
- **THEN** Nginx SHALL restart after PHP recreation so its upstream resolves the current PHP container

### Requirement: Demonstration acceptance
The deployment SHALL prove application success beyond container and transport health.

#### Scenario: Remote acceptance succeeds
- **WHEN** the deployed stack is verified
- **THEN** PHP SHALL report version 8.2 with the required runtime capabilities
- **THEN** storefront bootstrap APIs SHALL return valid JSON with business status 200
- **THEN** the management upgrade capability SHALL return business status 200 with `remote_enabled=false`, and legacy remote-upgrade operations SHALL return business status 400 without external requests
- **THEN** empty content-filter configuration SHALL leave input unchanged without a regular-expression warning
- **THEN** Workerman timers SHALL not pass a negative microsecond timeout to `stream_select`
- **THEN** storefront, admin, SPA, WebSocket, managed-process, database snapshot, and upload I/O checks SHALL pass
- **THEN** the existing `/franchise-demo/` endpoint SHALL continue returning HTTP 200
