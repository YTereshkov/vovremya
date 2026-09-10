# Catalog and Clients: parts 16–18, 38

## Services

`Catalog` owns active service definitions. A service has a name, required default
duration, and optional minimum and maximum durations in whole minutes. When a
bound is present, the invariant is `minimum <= default <= maximum`; every present
value is between 1 and 1440 minutes.

Deleting a service sets `deleted_at`. Active list and identifier queries always
filter deleted rows, so the API exposes no archive or restore workflow. Historical
snapshots and appointment references are introduced by later Scheduling parts.

## Clients and recipients

`Clients` owns clients, optional contact people, and channel connections. A
client can exist without a contact person and can itself be the recipient of a
channel. A connection records its provider, provisional or verified address,
recipient ownership, activation state, tenant-bound webhook routing identity,
and hashed secret/token material. Provider behavior and capabilities remain in
the `Communications` adapters from parts 28–31.

`Client.primary_channel_id` points to a channel owned by that exact client.
Composite PostgreSQL foreign keys include `organization_id` and owner IDs for
Client → primary channel, ContactPerson → Client, and ChannelConnection →
Client/ContactPerson. Therefore neither another tenant's connection nor another
client's connection can become the primary channel.

`ClientAbsence` records an inclusive local-date period, optional reason, one of
the two approved modes, and notification choice. `KEEP_PERMANENT_PLACE` retains
regular schedules, cancels affected planned appointments, and may explicitly
create one-off FreeWindow records for their intervals. `RELEASE_PERMANENT_PLACE`
ends the client's active regular schedules from the first absence date, removes
their future generated occurrences through the existing schedule lifecycle, and
does not create FreeWindow records. One-off appointments inside the entered
period are still cancelled. The Client record and its history remain intact.

All repositories obtain organization scope from `OrganizationContext`. Foreign
identifiers return 404. Mutations require the session `mutation` CSRF token.
Client list search is server-side and treats `%` and `_` as literal characters.

## HTTP surface

- `GET/POST /api/services`, `GET/PUT/DELETE /api/services/{id}`
- `GET/POST /api/clients`, `GET/PUT/DELETE /api/clients/{id}`
- `POST /api/clients/{id}/contacts`
- `PUT/DELETE /api/clients/{id}/contacts/{contactId}`
- `POST /api/clients/{id}/channels`
- `PUT/DELETE /api/clients/{id}/channels/{channelId}`
- `PUT /api/clients/{id}/primary-channel`
- `GET /api/clients/{id}/absence-impact`
- `POST /api/clients/{id}/absences`
- `POST /api/clients/{id}/channels/{channelId}/activation`
- `POST /api/clients/{id}/channels/{channelId}/deactivate`
- `GET/PUT /api/communications/channels[/default]`
- `GET/PUT/DELETE /api/communications/templates[/{type}]`

The React service screens follow mockups 37–40. Client list, create form, card,
and absence flow follow the approved mockups. ContactPerson remains optional;
the absence notification recipient is resolved from the client's active primary
ChannelConnection and may be either the client or a contact.
The service-specific confirmation-template section from mockups 38 and 40 uses
the organization template by default and may store one service override. Client
forms use the organization's configured default provider while the client card
shows connection status and starts the supported provider activation flow.
