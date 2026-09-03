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
runs one async worker. Production process management and worker counts belong to
the production hardening phase.

## Scheduler

The `infrastructure` schedule runs in its own process. Scheduled work is wrapped
in `RedispatchMessage`, so the scheduler only produces work and the async worker
executes it. Schedule state is stored in Redis, and only the latest missed run is
processed after downtime.

The infrastructure heartbeat interval is configured through
`INFRASTRUCTURE_HEARTBEAT_INTERVAL`. It is a technical setting, not a business
rule. Future business schedules should receive their own providers instead of
growing one global schedule class.
