# Scheduling and availability: parts 19–22

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
possible and the warning belongs to part 22. Specialist absences are integrated
in their later part. The availability check is advisory under concurrency; only
the transactional allocation insert is authoritative.

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
