## ADDED Requirements

### Requirement: Explicit acceptance snapshot
The migration verifier SHALL compare mutable acceptance data against an explicit, machine-readable snapshot captured before cutover.

#### Scenario: Complete snapshot is captured
- **WHEN** an operator captures an acceptance snapshot to an explicit external directory
- **THEN** the manifest SHALL contain a format version, database identity, normalized schema and data SHA256 digests, and a sorted entry for every uploaded file
- **THEN** every upload entry SHALL contain a normalized relative path, byte count, and SHA256 digest
- **THEN** no credential or database row content SHALL be written to the manifest

#### Scenario: Snapshot evidence is invalid
- **WHEN** the selected manifest is missing, malformed, unsupported, incomplete, duplicated, or contains a path outside the upload root
- **THEN** verification SHALL fail without falling back to hard-coded counts

#### Scenario: Acceptance data is compared
- **WHEN** the running database and upload tree are verified during a quiesced migration window
- **THEN** normalized schema and data digests SHALL match the selected snapshot
- **THEN** upload relative paths, sizes, and SHA256 digests SHALL match as a complete set
- **THEN** missing, extra, or changed data SHALL fail verification

### Requirement: Deployed PHP runtime gate
Acceptance SHALL verify the runtime inside the deployed PHP container rather than infer it from an image tag or candidate image.

#### Scenario: Runtime facts are valid
- **WHEN** the running PHP container is inspected
- **THEN** PHP SHALL report an 8.2 release
- **THEN** every required extension SHALL be loaded
- **THEN** OPcache SHALL be enabled and JIT SHALL be disabled

### Requirement: Stable managed processes
Acceptance SHALL detect supervised processes that are transiently running but repeatedly exit or restart.

#### Scenario: Process stability is sampled
- **WHEN** PHP-FPM, queue, timer, and Workerman are sampled twice over a bounded interval
- **THEN** every process SHALL report `RUNNING` in both samples
- **THEN** every process SHALL retain its PID and SHALL have zero unexpected restart evidence
- **THEN** the PHP container restart count SHALL remain zero for the acceptance window

#### Scenario: Exercised logs are clean
- **WHEN** acceptance workflows have completed
- **THEN** newly produced PHP, Nginx, Supervisor, queue, timer, and Workerman logs SHALL contain no fatal error, `TypeError`, unexpected deprecation, process termination, or repeated child restart
- **THEN** only explicitly recorded known-warning sites MAY be excluded

### Requirement: Observable local workflows
Acceptance SHALL exercise the local interfaces and writable storage used by representative application traffic.

#### Scenario: HTTP and SPA routes respond
- **WHEN** storefront, admin, SPA fallback, and representative public API routes are requested locally
- **THEN** every route SHALL return its expected successful HTTP status
- **THEN** the existing warm-storefront latency limit SHALL remain satisfied

#### Scenario: WebSocket routes upgrade
- **WHEN** valid WebSocket upgrade requests are sent to `/notice` and `/msg`
- **THEN** both routes SHALL return HTTP 101

#### Scenario: Upload mount supports real I/O
- **WHEN** a unique probe file is created as the PHP runtime user under the mounted uploads directory
- **THEN** its contents SHALL be read back exactly
- **THEN** the probe file SHALL be removed before verification exits
- **THEN** any create, read, content, or cleanup failure SHALL fail verification

### Requirement: Deterministic acceptance self-tests
Pure snapshot and acceptance-decision logic SHALL be testable without Docker, MySQL, or external services.

#### Scenario: Failure fixtures are exercised
- **WHEN** focused self-tests run against changed digests, changed uploads, duplicate or escaping paths, wrong runtime facts, unstable processes, disallowed logs, and invalid route statuses
- **THEN** every invalid fixture SHALL be rejected for the intended reason
- **THEN** a valid fixture SHALL pass
