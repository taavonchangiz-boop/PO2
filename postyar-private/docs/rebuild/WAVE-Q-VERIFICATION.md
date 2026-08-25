# Wave Q Verification Record

- Scope: observability, operational endpoints, durable job queue foundation, deployment boundary, API/web health parity.
- Static gate: PASS.
- Real PHP-FPM/MySQL/Redis/load test: NOT RUN in this environment.
- Required production test environment: PHP 8.2+, MySQL/MariaDB InnoDB, Redis, reverse proxy, representative dataset.
- Security invariant: queue worker never evaluates a job type/payload as PHP code; handlers must be allow-listed in source.
- Public health endpoints expose no database/schema details.
- Metrics endpoint fails closed (404) without configured operator token.
