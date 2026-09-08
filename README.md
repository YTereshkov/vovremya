# Vovremya

Vovremya is a Symfony 7.4 application running on PHP 8.4. The repository also
contains the approved UI mockups used as the visual source of truth.

## Local development

Start the development environment:

```bash
docker compose up --build -d
```

Check container status:

```bash
docker compose ps
```

Open the backend health endpoint:

```text
http://localhost:8080/health
```

Open the React application:

```text
http://localhost:5173
```

Run Symfony console commands:

```bash
docker compose exec php php bin/console
```

Check Doctrine configuration and migration state:

```bash
docker compose exec php php bin/console doctrine:schema:validate
docker compose exec php php bin/console doctrine:migrations:status
```

## Redis and background processes

Application cache, short-lived data, rate limiter state, and scheduler state use
separate namespaced Redis pools. Messenger uses separate Redis Streams for the
`async` and `failed` transports.

The default Compose stack runs two background processes:

- `messenger` consumes application messages from `async`;
- `scheduler` produces scheduled messages from `scheduler_infrastructure` and
  `scheduler_regular_scheduling`.

Inspect queues and the schedule:

```bash
docker compose exec php php bin/console messenger:stats
docker compose exec php php bin/console debug:scheduler
```

Dispatch and inspect the infrastructure heartbeat:

```bash
docker compose exec php php bin/console app:infrastructure:heartbeat
docker compose exec php php bin/console app:infrastructure:heartbeat --status
```

Every Redis Messenger worker must have a unique
`MESSENGER_CONSUMER_NAME`. The Compose configuration provides stable names for
its single local worker; deployments with multiple worker replicas must override
them per process.

Module ownership and dependency rules are documented in
[`docs/architecture/modules.md`](docs/architecture/modules.md).
Infrastructure conventions are documented in
[`docs/architecture/infrastructure.md`](docs/architecture/infrastructure.md).
Frontend visual conventions are documented in
[`docs/frontend/design-system.md`](docs/frontend/design-system.md).

## Build and tests

Run backend tests. The command creates the isolated test database when needed
and applies pending migrations before PHPUnit starts:

```bash
docker compose exec php composer test:backend
```

Create the first organization administrator. The password is requested twice
through hidden interactive input and is never accepted as a CLI argument:

```bash
docker compose exec php php bin/console app:admin:create
```

Check and build the React application:

```bash
docker compose exec node npm run typecheck
docker compose exec node npm run build
```

Run Playwright smoke tests in the dedicated Compose profile:

```bash
docker compose --profile test run --rm playwright npm run test:e2e
```

Run authenticated specialist and working-hours scenarios on desktop and mobile:

```bash
bash bin/test-workforce-e2e
```

Run authenticated service and client-directory scenarios:

```bash
bash bin/test-catalog-clients-e2e
```

Run one-off appointment creation, soft-warning, and hard-conflict scenarios:

```bash
bash bin/test-appointments-e2e
```

Run responsive calendar, specialist-filter, and appointment-details scenarios:

```bash
bash bin/test-calendar-e2e
```

Run regular-schedule creation, day changes, materialization, and termination:

```bash
bash bin/test-regular-schedules-e2e
```

This runner migrates the development database, creates disposable test
organizations, runs Playwright, and removes only those marked organizations on
exit. Without the runner, authenticated Workforce scenarios are skipped; the
public smoke tests still run. Do not use these fixtures against production.

Specialist profiles, weekly working hours, per-day lunches, and additional
working days are available under **Settings → Specialists**. Link a specialist
to the signed-in administrator to use **My schedule**. The Workforce model and
API conventions are documented in
[`docs/architecture/workforce.md`](docs/architecture/workforce.md).

Service definitions are managed under **Settings → Services**. The **Clients**
section provides client search, optional contact people, channel metadata, and
primary-recipient selection. Catalog and Clients ownership rules are documented
in [`docs/architecture/catalog-clients.md`](docs/architecture/catalog-clients.md).

Scheduling occupancy and hard availability are backed by PostgreSQL exclusion
constraints. The check endpoint and concurrency guarantees are documented in
[`docs/architecture/scheduling-availability.md`](docs/architecture/scheduling-availability.md).
The calendar read model, organization-timezone boundaries, and responsive views
are documented in the same architecture note. The same note documents regular
rules, configurable `REGULAR_SCHEDULE_HORIZON_DAYS`, materialization, and
`ScheduleGenerationIssue`.

Stop the environment without deleting database or Redis data:

```bash
docker compose down
```

Host ports can be changed through `APP_HTTP_PORT`, `VITE_PORT`,
`POSTGRES_PORT`, and `REDIS_PORT` environment variables.

Default host ports are `8080` for HTTP, `5173` for Vite, `54329` for
PostgreSQL, and `63799` for Redis. Containers use the standard service ports
internally.
