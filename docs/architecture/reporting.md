# Reporting statistics

`Reporting` owns only tenant-scoped read queries. Appointment, confirmation,
transfer, and waiting-list state remains owned by their business modules.

`GET /api/statistics` reports one calendar month in the organization's timezone.
Every metric is filtered by the occurrence time (`starts_at`) of its appointment
or free window, not by the time when the administrator recorded its result.
The optional specialist filter is resolved inside the current organization.

- `planned` counts appointments still in the schedule, excluding the historical
  source occurrence of a completed reschedule. Final cancellations and no-shows
  remain part of the original planned workload.
- Cancellation counts use the appointment's independent result and stored late
  cancellation flag. Confirmation counts use the independent confirmation
  request status. No-shows without confirmation include both unanswered and
  never-requested confirmations.
- Transfer requests are attributed to the original appointment's month;
  successful transfers are requests with status `COMPLETED`.
- Freed windows include closed historical windows. A window is counted as filled
  from waiting only after an accepted `WAITING_LIST` offer; moving an already
  scheduled client earlier is not counted as a waiting-list fill.

PostgreSQL remains authoritative. These counts have no separate cache or write
model, so a schedule mutation becomes visible on the next statistics request.
