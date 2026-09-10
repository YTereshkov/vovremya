# Workforce: parts 14–15, 37

## Ownership and persistence

`Workforce` owns specialist profiles, their working-time configuration, and
dated specialist absences.
`Specialist` is the aggregate root: name, specialization, optional administrator
link, and a seven-day weekly-hours value stored as JSONB. Each weekday has an
enabled flag, a working interval, and an optional lunch interval. Disabled days
persist neither working time nor lunch.

`AdditionalWorkingDay` belongs to one specialist and supplies a dated working
interval. Dates and clock times are local to the organization timezone. The
interval supplements weekly hours; it does not remove or replace the weekly
configuration. There is at most one additional interval per specialist/date.

`SpecialistAbsence` records an inclusive local-date period, absence type,
optional comment, and whether affected clients should be notified. Creating an
absence is transactional with cancellation of every still-planned appointment
inside the period. Those appointments receive `CANCELLED_BY_SPECIALIST`, release
their allocations, cancel pending confirmation/reminder intents, and never
create FreeWindow records. Regular schedules remain active and continue after
the absence.

Intervals must use valid `HH:MM` values with start before end within the same
day. Lunch must fit inside that day's working interval. Lunch is not an absence
or a hard booking conflict. A specialist absence is a hard availability
conflict; manual booking and regular materialization both reject its dates.

All reads and writes use `OrganizationContext`. HTTP clients cannot select the
organization. Foreign specialist, administrator, and additional-day identifiers
return 404. Mutations require the session's `mutation` CSRF token, obtained from
`GET /api/auth/csrf` and sent through `X-CSRF-Token`.

Administrator and specialist references are scalar ULIDs in Doctrine. PostgreSQL
composite foreign keys include `organization_id` and prevent cross-tenant links.
`WorkforceSchemaConstraints` adds the same keys to Doctrine's comparison schema
so schema validation and future migration diffs retain these database guarantees.
An administrator can link to at most one specialist in their organization.

## HTTP surface

| Method | Path | Purpose |
| --- | --- | --- |
| GET / POST | `/api/specialists` | List / create |
| GET / PUT / DELETE | `/api/specialists/{id}` | Read / edit / delete profile |
| PUT | `/api/specialists/{id}/weekly-hours` | Replace all seven weekdays |
| POST | `/api/specialists/{id}/additional-days` | Add a dated interval |
| PUT / DELETE | `/api/specialists/{id}/additional-days/{dayId}` | Edit / remove dated interval |
| GET | `/api/specialists/{id}/absence-impact` | Preview affected planned appointments |
| POST | `/api/specialists/{id}/absences` | Record absence and cancel affected appointments |

Invalid input returns 422, invalid CSRF returns 403, and uniqueness conflicts
return 409. Lists include today's working intervals calculated in the organization
timezone. React handles input and display; backend validation remains definitive.

## UI and checks

The specialist list, profile, weekly-hours form, absence form, and absence
history follow the approved mockups. Initials are the avatar fallback. The same
responsive components serve desktop and mobile. Query cache keys include
organization and administrator identifiers; successful mutations invalidate
the Workforce and related scheduling queries.

PHPUnit covers interval rules, HTTP CRUD, absence effects, CSRF, tenant
boundaries, and PostgreSQL constraints. `bash bin/test-workforce-e2e` checks the
working-time UI; `bash bin/test-absence-e2e` checks specialist and client absence
flows on desktop and mobile with disposable development-only tenants.
