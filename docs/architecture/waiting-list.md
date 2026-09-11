# Waiting List and free-window matching: parts 41–42

## Waiting need

`WaitingListEntry` stores one active waiting configuration for a client. It is
tenant-owned and refers to a client, service, and optionally one specialist.
The configuration also keeps the required weekly frequency, the first
applicable local date, readiness for one-off windows, and an optional
administrator comment.

`WaitingListAvailability` stores the acceptable local time range separately
for each selected weekday. A client may therefore accept different ranges on
different days. Ending a waiting entry is a lifecycle transition: the row and
its availability remain historical, while the client can later receive a new
active configuration.

`ContactPerson` is not part of waiting-list ownership. The waiting need belongs
to the client regardless of whether future communication is addressed to the
client or an optional contact person.

## Duration and service lifecycle

Matching a waiting client uses the current default duration of the selected
active service. Changing that default affects future matching but does not
rewrite existing appointments or their immutable service snapshots.

A soft-deleted service remains readable for an existing waiting configuration
so the administrator can understand its history. It cannot be selected for a
new or updated configuration and its waiting entry produces no new match.
There is no user-facing service archive.

## Candidate matching

`GET /api/free-windows/{id}/candidates` first verifies that the explicit
`FreeWindow` is still open and currently available. An ordinary calendar gap is
never treated as a free window.

Candidates are returned in two sections:

1. `moveEarlier`: later planned appointments on the same local day, for the
   same specialist and service, whose actual appointment duration fits the
   window. Availability is checked again while excluding the candidate's
   current appointment.
2. `waitingClients`: active waiting entries matching service, optional
   specialist, effective date, weekday, local time range, current service
   duration, and readiness for one-off windows.

Clients currently absent on the window date are excluded. A client already
shown in `moveEarlier` is not duplicated in `waitingClients`.

The UI keeps all FreeWindow cards visible but loads candidates only after the
administrator expands a specific window. Initial list rendering therefore does
not start one candidate request per window.

Candidate calculation is advisory. It creates no appointment, transfer,
message, offer, reservation, or `ScheduleAllocation`. Accepting a one-off
window does not reduce or end the client's permanent waiting need. Creating an
offer and any `OFFER_RESERVATION` belongs to parts 43–45.

## Tenant isolation

Both waiting tables have immutable `organization_id`, unique
`(organization_id, id)` keys, and organization-aware foreign keys to client,
service, optional specialist, and parent entry. Application lookups use the
current `OrganizationContext`; foreign-tenant identifiers behave as not found.

Cross-module data is obtained only through Application contracts owned by
Clients, Catalog, Workforce, and Scheduling. Matching queries and the HTTP flow
repeat tenant scope rather than trusting identifiers from the request.
