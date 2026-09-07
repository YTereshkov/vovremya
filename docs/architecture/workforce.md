# Workforce: parts 14–15

## Ownership and persistence

`Workforce` owns specialist profiles and their working-time configuration.
`Specialist` is the aggregate root: name, specialization, optional administrator
link, and a seven-day weekly-hours value stored as JSONB. Each weekday has an
enabled flag, a working interval, and an optional lunch interval. Disabled days
persist neither working time nor lunch.

`AdditionalWorkingDay` belongs to one specialist and supplies a dated working
interval. Dates and clock times are local to the organization timezone. The
interval supplements weekly hours; it does not remove or replace the weekly
configuration. There is at most one additional interval per specialist/date.

Intervals must use valid `HH:MM` values with start before end within the same
day. Lunch must fit inside that day's working interval. Lunch is not an absence
or a hard booking conflict. Booking availability and warnings belong to later
Scheduling parts and are not implemented here.

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

Invalid input returns 422, invalid CSRF returns 403, and uniqueness conflicts
return 409. Lists include today's working intervals calculated in the organization
timezone. React handles input and display; backend validation remains definitive.

## UI and checks

The specialist list, profile, and weekly-hours form follow approved mockups
18–20. Initials are the avatar fallback. No messenger connection or specialist
absence status is fabricated: those workflows are not implemented in this batch.
The same responsive components serve desktop and mobile. Query cache keys include
organization and administrator identifiers; successful mutations invalidate the
Workforce queries.

PHPUnit covers interval rules, HTTP CRUD, CSRF, tenant boundaries, and PostgreSQL
constraints. `bash bin/test-workforce-e2e` checks the authenticated UI on desktop
and mobile using disposable development-only tenants, including persisted
weekday differences and optional lunches after reload.
