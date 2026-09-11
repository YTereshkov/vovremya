# Scheduling and availability: parts 19–27, 34–40

## Concrete occupancy

`schedule_allocations` is the single database-level source of occupied time. An
active allocation owns a half-open `[starts_at, ends_at)` interval for one
specialist. Timestamps are stored as `timestamptz`; working-hour rules are
evaluated in the organization timezone.

The supported allocation types are:

- `APPOINTMENT` for a concrete appointment;
- `OFFER_RESERVATION` for a future FreeWindow or PermanentPlace offer.

Transfer options never create allocations and therefore never reserve proposed
times. The offer workflows are not implemented in this batch. Releasing an
allocation sets `released_at`; the row remains as technical history but stops
blocking its interval.

PostgreSQL `btree_gist` and an exclusion constraint reject overlapping active
intervals for the same `(organization_id, specialist_id)`. Adjacent intervals
are valid because ranges use `[)`. A composite foreign key prevents linking a
specialist from another tenant. The source index supports future lookup by
allocation type and owning business object.

The write sequence for later Scheduling use cases is:

1. perform the availability check;
2. start the business transaction;
3. insert the appointment and allocation;
4. map PostgreSQL SQLSTATE `23P01` to `TimeUnavailable` / HTTP 409 if another
   transaction won the race.

No specialist/date transaction lock is required. The exclusion constraint is
the final double-booking guarantee.

## Hard availability

`POST /api/availability/check` accepts:

```json
{
  "specialistId": "01a07...",
  "startsAt": "2026-09-07T10:00:00+03:00",
  "endsAt": "2026-09-07T11:00:00+03:00"
}
```

All timestamps require an explicit offset. The endpoint is authenticated and
requires the session mutation CSRF token. It returns 200 when available, or 409
with a stable conflict DTO:

```json
{
  "available": false,
  "conflict": {
    "code": "TIME_ALREADY_UNAVAILABLE",
    "message": "Время уже недоступно."
  }
}
```

Hard availability checks the tenant-scoped specialist, a single local calendar
day, weekly working hours, additional working days, and active allocations.
Outside working hours uses `SPECIALIST_NOT_WORKING`; overlap uses
`TIME_ALREADY_UNAVAILABLE`. Foreign specialist identifiers return 404.

Lunch is deliberately not a hard conflict: manual booking over lunch remains
possible after accepting the part 22 warning. A dated specialist absence returns
`SPECIALIST_ABSENT` as a hard conflict. The availability check is advisory under
concurrency; only the transactional allocation insert is authoritative.

PHPUnit covers working boundaries, days off, additional days, lunch behavior,
released allocations, tenant isolation, adjacent intervals, and a real two-
transaction race in which exactly one overlapping insert succeeds.

## One-off appointment creation

`POST /api/appointments` accepts tenant-scoped specialist, client, and active
service identifiers plus a local date and start time. Local values are resolved
in the organization timezone. `durationMinutes` may be omitted to use the
service default; an explicit value must remain inside the service minimum and
maximum when those bounds exist.

Appointment creation copies the service name and duration settings into an
immutable snapshot. A service removed through the user-facing delete action is
soft-deleted, cannot be selected for new appointments, and remains referenced
by historical appointments. The appointment, its `APPOINTMENT` allocation, and
any warning audit event commit in one short transaction.

The application checks hard availability before the transaction. PostgreSQL's
exclusion constraint still decides concurrent races: SQLSTATE `23P01` becomes a
`HARD_CONFLICT` response with HTTP 409. No additional specialist/date lock is
used.

## Soft warnings

Lunch overlap and a break shorter than 15 minutes return HTTP 409 with
`kind: SOFT_WARNING` and stable warning codes. They do not occupy or reject the
interval. The caller may repeat the same create command with the current codes
in `acceptedWarnings`.

The backend recalculates warnings on every attempt. A warning that is currently
present but was not explicitly accepted prevents creation; newly appearing
warnings therefore cannot be bypassed by stale UI state. Accepted warnings are
recorded as a `SOFT_WARNINGS_ACCEPTED` appointment event. Actual overlap remains
a hard conflict regardless of `acceptedWarnings`.

## Calendar read model

`GET /api/calendar?from=YYYY-MM-DD&to=YYYY-MM-DD` returns concrete appointments
for an inclusive local-date range of at most 31 days. Optional `specialistId`
filters one specialist. Date boundaries use the authenticated organization's
timezone and are converted to instants before querying `timestamptz` columns.

The DBAL reader executes one tenant-scoped query joining appointment, client,
and specialist data. Results are ordered by start instant, specialist name, and
appointment identifier. Existing `(organization_id, starts_at, id)` and
`(organization_id, specialist_id, starts_at)` indexes match the unfiltered and
filtered range queries. No per-row lookups are used.

Calendar responses use the appointment's immutable service snapshot. Renaming
or soft-deleting the catalog service therefore does not rewrite historical
calendar entries. `GET /api/appointments/{id}` exposes the same read model for
the appointment details screen and returns 404 for another tenant's identifier.

Mobile calendar rendering uses day and agenda-week views. Desktop rendering
uses day columns, a week time grid, an optional specialist filter, and a
fullscreen week mode. A single-specialist organization is presented directly
without a redundant filter.

## Regular schedules and materialization

`RegularSchedule` owns the client, specialist, service snapshot, start date and
optional first inactive date. `RegularScheduleDay` keeps one weekday, local
start time, duration and its own active date interval. Different weekdays may
therefore use different times and durations. Rule changes create a new day
version; old versions remain available for history.

Concrete appointments are materialized only inside the rolling horizon set by
`REGULAR_SCHEDULE_HORIZON_DAYS`. The default is `60`, but this is a technical
configuration value, not a domain invariant. The daily
`scheduler_regular_scheduling` schedule dispatches materialization through the
existing async Messenger transport for every organization under an explicit
tenant context.

Initial creation checks every occurrence currently inside the horizon. Any hard
conflict rejects the whole rule and returns the concrete dates through HTTP
409. Later materialization checks availability again before every insert. An
unavailable date creates or refreshes a tenant-owned `ScheduleGenerationIssue`;
it is never silently skipped. Retrying an issue reruns current availability and
resolves the issue only after the appointment and allocation exist.

A generated appointment references both its schedule and day version. Changing
or ending a rule marks affected future appointments as
`REMOVED_FROM_SCHEDULE`, releases their allocations, and keeps the appointment
rows for history. Calendar range queries include only `PLANNED` appointments.
The partial unique index permits one planned occurrence per schedule/date while
allowing removed historical versions to coexist with a replacement.

Materialization follows the same concurrency boundary as one-off creation:
availability is advisory, and the transactional allocation insert is final.
PostgreSQL's exclusion constraint remains the authoritative protection from
double booking; no specialist/date transaction lock is used.

## Appointment result and history

Confirmation status and appointment result are independent. `Appointment`
stores the current result (`CONDUCTED`, client/specialist cancellation,
`NO_SHOW`, or later `RESCHEDULED`) while append-only `AppointmentEvent` rows
keep the human-facing history. System and client-originated events may have no
administrator actor. Conducted and no-show results cannot be recorded before
the appointment starts; transfer cannot be simulated through the generic
result endpoint.

Late cancellation is calculated only for client cancellation against the
tenant setting `late_cancellation_hours` (12 by default). “Less than N hours”
is strict: cancellation exactly at the boundary is not late. A respectful
reason is valid only for a late client cancellation. Changing a concrete
regular occurrence never edits or terminates its `RegularSchedule` or day
rule, and the existing appointment row and all prior events remain intact.

Client or specialist cancellation releases the appointment allocation in the
same database transaction. Any pending confirmation/reminder intent is marked
cancelled, preventing an already published worker command from beginning a new
send. The independent confirmation response remains recorded for history. If
an administrator corrects a cancellation to conducted/no-show, the original
allocation is restored; PostgreSQL rejects that correction when another active
allocation already occupies the interval.

## Managed free windows

`Waiting.FreeWindow` is created only when an administrator explicitly requests
it while cancelling a future appointment by the client. Ordinary gaps in the
calendar are not FreeWindow records. One source appointment can own at most one
window; repeated result submissions reopen the same row instead of creating a
duplicate. Changing the result or clearing the checkbox closes that window.

FreeWindow itself creates no schedule allocation and reserves no time. Listing
open windows repeats current Scheduling availability, so a subsequently booked
or otherwise unavailable interval is not offered as free. Reservation belongs
only to later FreeWindow/PermanentPlace offers through `OFFER_RESERVATION`.

Candidate selection for an open FreeWindow is documented in
[`waiting-list.md`](waiting-list.md). It remains a read-only recommendation:
neither a later appointment candidate nor a Waiting List match creates an
allocation or reserves the interval.

## Absence effects

Specialist and client absence use cases first preview tenant-scoped planned
appointments, then persist the absence and all scheduling effects in one
transaction. Existing factual results are never rewritten. Cancellation keeps
the Appointment and append-only history, releases its allocation, and suppresses
pending confirmation/reminder delivery.

Specialist absence always cancels affected appointments with
`CANCELLED_BY_SPECIALIST` and never creates FreeWindow records. Regular schedule
materialization repeats availability for every occurrence; an absence date
therefore creates `ScheduleGenerationIssue` with `SPECIALIST_ABSENT` instead of
being skipped silently.

Client `KEEP_PERMANENT_PLACE` cancellation leaves the regular rule active and
may create explicit FreeWindow records. Client `RELEASE_PERMANENT_PLACE` ends
active regular schedules at the absence start, invokes the established removal
lifecycle for future generated occurrences, and creates no FreeWindow records.
This does not delete the client or past appointments/results.

## Appointment transfers

`TransferRequest` owns the transfer lifecycle for one source Appointment;
`TransferOption` stores each proposed interval and an opaque single-use callback
token hash. A request may wait for options, contain sent options, or end as
completed, declined, or cancelled. It has no automatic timeout.

Transfer options do not create `ScheduleAllocation` rows and do not reserve
time. When the client selects an option, the backend locks the source
Appointment and then its request, rechecks current availability while excluding the source appointment, releases
the source allocation, and inserts the new `APPOINTMENT` allocation in one
transaction. PostgreSQL's exclusion constraint remains the final concurrency
guarantee; SQLSTATE `23P01` becomes the domain result “время уже недоступно”,
while the request stays open so another proposed option can be selected.

Successful transfer preserves the source Appointment with result
`RESCHEDULED`, creates a new one-off Appointment from the immutable service
snapshot, and records human-facing `from`/`to` history. Moving one materialized
regular occurrence does not end or edit its `RegularSchedule`. Cancelling the
source appointment, applying an absence, or removing a generated occurrence
also cancels its active transfer request.

Result changes, absence processing, regular-schedule removal, and transfer
selection use the same `Appointment -> TransferRequest` row-lock order. State is
reloaded and revalidated after acquiring the lock, so a stale pre-transaction
read cannot overwrite a concurrent transfer. This is lifecycle coordination,
not a specialist/date booking lock; interval exclusivity still belongs to the
PostgreSQL exclusion constraint.
