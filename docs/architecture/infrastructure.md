# Infrastructure foundation

## Redis

Redis has bounded responsibilities:

- `cache.app` stores application cache;
- `cache.temporary` stores short-lived technical data;
- `cache.rate_limiter` is reserved for rate limiters introduced with actual endpoints;
- `cache.scheduler` stores Symfony Scheduler state;
- `cache.infrastructure` stores infrastructure health data;
- `vovremya_async` is the Messenger work stream;
- `vovremya_failed` is the Messenger failure stream.

Cache pools use Symfony namespaces derived from the `vovremya` prefix seed.
Business cache invalidation belongs to the application use case that changes the
source data. No broad cache layer is introduced before a concrete need exists.

## Messenger

Messages implementing `AsyncMessage` route to `async`. The Redis transport uses
consumer groups and removes acknowledged or rejected entries from the work
stream. Failed messages are copied to the separate `failed` stream after three
retries with bounded exponential delays.

Each running worker must use a unique `MESSENGER_CONSUMER_NAME`. Local Compose
runs one async worker. Production Compose runs Messenger and Scheduler as
separate restartable services with unique consumer names. PostgreSQL outbox and
inbox state remains authoritative across worker restarts.

## Scheduler

Infrastructure, regular scheduling, communications, and waiting schedules run
as separate scheduler receivers. Scheduled work is wrapped in
`RedispatchMessage`, so schedulers only produce work and the async worker
executes it. Schedule state is stored in Redis, and only the latest missed run
is processed after downtime.

The infrastructure heartbeat interval is configured through
`INFRASTRUCTURE_HEARTBEAT_INTERVAL`. It is a technical setting, not a business
rule. `scheduler_waiting` extends concrete reservations for active
PermanentPlace offers once per day. Repeated runs are safe and do not duplicate
allocations.

## Production runtime

`compose.prod.yaml` targets a single ordinary VPS. It builds immutable PHP and
Caddy images for a release tag, runs database migrations as a one-shot service,
and starts PHP-FPM, Caddy, Messenger, Scheduler, PostgreSQL, and Redis with
health checks and bounded rotated logs. PostgreSQL and Redis are not published
to the host network.

Caddy terminates HTTPS, serves the compiled React SPA (including direct client
routes), and forwards only API, health, and webhook paths to Symfony. Webhook
paths are excluded from access logs because their routing credential is
tenant-bound. Application containers run read-only with writable temporary
filesystems for Symfony runtime data.

Production secrets live only in an operator-owned mode-600 environment file.
The deployment validates that file before building or starting a release.
Backups use PostgreSQL custom format and must be copied to independent storage;
rollback changes application images only and never performs a down migration.
The operational procedure and restore cautions are documented in
`docs/deployment-vps.md`.
