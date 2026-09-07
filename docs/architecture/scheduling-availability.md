# Scheduling availability: parts 19–20

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
