# Communications: parts 28–33

`Communications` owns provider-neutral notification intents, outbound messages,
and the webhook inbox. The module does not know how MAX, Telegram, or WhatsApp
talk to their APIs; each provider is supplied later through a `ChannelProvider`
adapter.

## Transactional outbox

`NotificationOutbox::queue()` stores a `NotificationIntent` and its
`OutboundMessage` in one database transaction. `communication_outbox` is the
durable outbox record: the application never treats Redis or a Messenger
envelope as the source of truth for a message.

The communications scheduler publishes pending outbox rows every minute. The
publisher is at-least-once; the send handler claims a row with a conditional
tenant-scoped update before calling an adapter, so duplicate scheduler runs do
not send the same row concurrently. A five-minute processing lease lets the
scheduler recover rows left in `PROCESSING` by a terminated worker. Every
provider request receives the stable outbox identifier as its idempotency key,
so adapters can safely resolve the ambiguous "provider accepted, database was
not updated" outcome. A provider failure returns the row to `PENDING` and is
retried by Messenger. Unsupported providers are recorded as `FAILED` and remain
visible for later operational handling.

`NotificationIntent` contains the business type, recipient channel reference,
payload, and optional tenant-local deduplication key. `OutboundMessage` stores a
provider/address/body snapshot plus button and metadata payloads. Later
template and channel parts may build these records without changing scheduling
code. A cancelled intent is excluded both from scheduler publication and from
the atomic send claim, so an already queued Messenger envelope cannot start a
new send for it.

When an appointment receives any final result, Scheduling calls the
Communications application boundary that cancels pending confirmation and
reminder intents for that tenant and appointment. Credentials, channel state,
and already recorded confirmation responses remain untouched.

## Provider contract

`ChannelProvider` exposes only provider-neutral operations: provider identity,
capabilities, outbound send, and webhook decoding. `ChannelProviderRegistry`
resolves adapters by `CommunicationProvider`; no business use case imports a
MAX, Telegram, or WhatsApp SDK.

Capabilities are explicit (`supportsButtons`, `supportsMessageEdit`,
`supportsDeliveredStatus`, `supportsReadStatus`, `supportsDeepLink`). A later
UI must not display delivery/read information when the selected adapter does
not advertise it.

## Webhook inbox

`communication_webhook_inbox` is an append-once ingress log keyed by
`(organization_id, provider, external_event_id)`. `WebhookInboxRecorder` uses
PostgreSQL `INSERT ... ON CONFLICT`, so concurrent duplicates return the
original row and receive the same HTTP `200` acknowledgement with the
`{accepted, duplicate}` response contract for every configured provider.

Only a configured provider adapter can activate its endpoint. The adapter must
authenticate the original request body and headers before anything is stored;
the controller also applies a tenant/provider/IP rate limit and a 256 KiB body
limit. JSON decoding and persistence happen only after successful
authentication.

New and recoverable inbox rows are handed to `ProcessWebhookInbox` through
Messenger. Dispatch from the HTTP request is best-effort: the scheduler also
publishes every unprocessed row, so a Redis failure after the database commit
cannot lose the work. Processing uses the same five-minute claim lease as the
outbox, preventing concurrent handling while recovering work abandoned by a
terminated worker. Provider parsing therefore never runs in the webhook HTTP
request.

All tables carry `organization_id`, composite tenant foreign keys, and indexes
starting with the tenant column. Messenger messages that carry tenant data
implement `OrganizationAwareMessage`, so the existing middleware re-checks the
organization before the handler runs.

## MAX adapter (part 31)

`MaxChannelProvider` is the first concrete adapter. It uses the MAX REST API
through `MaxApiClient`; the transport endpoint and credentials come from
`MAX_API_BASE_URL` and the protected provider bot credential. Webhook secrets
are stored as hashes on the tenant-owned channel connection, never as one
global secret. Credentials are kept out of message bodies and URLs.

Outbound messages are rendered as MAX `text` plus an inline keyboard with
callback or link buttons. The adapter exposes buttons and deep links as
capabilities; message editing remains disabled until the provider-neutral
contract has a real edit operation. Delivered/read statuses are deliberately
not exposed because MAX does not document corresponding update events. The
outbox id remains an internal retry/deduplication key; MAX's documented API
does not guarantee idempotency for a custom request header, so ambiguous
transport success is not presented as exactly-once delivery.

MAX webhook requests are authenticated with `X-Max-Bot-Api-Secret` before they
are persisted. The controller acknowledges MAX with HTTP 200 as required by
the platform. Callback updates become normalized button events; ordinary text
updates are marked unsupported and never interpreted as a business command.

New channel connections start in `PENDING` state. The tenant-scoped client API
stores the webhook secret hash, while a matching MAX `bot_started` event is the
external recipient confirmation that moves the connection to `ACTIVE`. Outbound
messages require an active, verified connection; deactivated connections remain
disabled. Normalized events have their own claim/retry/processed lifecycle and
are dispatched through the provider-neutral Messenger contract.

Outbound metadata currently has a strict allowlist containing only the safe
diagnostic `source` string. Unknown, nested, credential-like, or recipient
override values are rejected before persistence and the surviving safe metadata
is passed unchanged to the provider adapter.

## Appointment confirmations (parts 32–33)

Confirmation settings are tenant-owned and use the organization timezone. By
default, the request is sent at 14:00 on the day before the appointment, a
still-pending request becomes `NO_RESPONSE` at 16:00, and one repeat reminder
is due two hours before the appointment but never before 07:00. Quiet hours
delay outbound work rather than discarding it. The scheduler scans due work
every minute; database uniqueness and outbox deduplication make repeated or
overlapping scans safe.

The repeat reminder is sent for `PENDING`, `NO_RESPONSE`, and `CONFIRMED`
requests. It is skipped for a `CANNOT_ATTEND` response, an appointment with a
recorded final result, an already started appointment, or a request that already
received its reminder.

Each appointment has at most one confirmation request bound to the active
primary `ChannelConnection` selected when the request is created. The request
creates opaque, separately hashed, single-use actions for `CONFIRMED`,
`CANNOT_ATTEND`, and `REQUEST_TRANSFER`; raw tokens exist only in the callback buttons. A normalized
provider event must match the tenant, original channel connection, action and
token before the atomic database transition succeeds. Ordinary text remains
unsupported; a stale action or a callback from another recipient cannot change
the appointment.

`NO_RESPONSE` is an operational attention state, not a callback expiry. A
verified late answer is accepted until the appointment starts, while preserving
the recorded no-response timestamp. Confirmation status remains independent
from the appointment result. A verified `REQUEST_TRANSFER` action creates a
tenant-bound `TransferRequest`; later option callbacks are routed through the
same provider-neutral normalized-event boundary and never reserve their times.

## Message templates (part 29)

`Communications` owns organization-wide templates for confirmation, transfer,
free-window, and permanent-place messages. The supported variables are `{date}`, `{time}`,
`{service}`, `{client_name}`, and `{contact_name}`. Unknown or malformed
placeholders are rejected before persistence. Each type has a usable built-in
Russian default, while an organization may save or restore its override.

`Catalog.Service` may contain an optional confirmation-template override. It is
used only for confirmation messages and takes precedence over the organization
template; other message types always use their matching organization template.
The UI exposes no separate template archive. Confirmation button labels are
stored with the organization confirmation template and are used by real
provider-neutral callback buttons. The preview substitutes safe demo values for
all placeholders; template bodies still store the placeholders themselves.

## Channel connection lifecycle (part 30)

An organization selects one configured provider as the default for new clients.
Each client channel still records its own provider and recipient, which may be
the client or an optional contact person. Provider capabilities returned by the
backend are the only source for capability-dependent UI.

MAX connections move through `PENDING`, `ACTIVE`, and `DISABLED`. Starting an
activation creates a random, time-limited token and persists only its SHA-256
hash. The official MAX deep link carries the opaque token; a matching
`bot_started` webhook atomically consumes it and replaces the provisional
address with the verified provider user identifier. The update is scoped by
organization and connection, so replay, expiry, wrong-recipient, wrong-tenant,
and concurrent second consumption cannot activate a connection.

The connection-specific webhook routing key and secret must be provisioned by
the provider integration before an activation link can be issued. Bot username,
activation lifetime, API credential, and webhook secret material remain
deployment/provider configuration and are never returned by settings APIs.

## Notification center and delivery control (part 46)

`Reporting` builds the notification center as a tenant-scoped read model from
the modules that own confirmation, transfer, free-window, permanent-place, and
communication state. It does not own or mutate that business state. Each
administrator has an independent `read_through` marker; marking the list read
does not change appointment, offer, or delivery lifecycles.

Outbound delivery state is separate from a client's business response. The
monotonic message lifecycle is `SENT -> DELIVERED -> READ`; a provider-reported
delivery failure may move only `SENT` to `FAILED`. Delivered/read updates are
accepted only when the active provider adapter advertises the corresponding
capability and the normalized event matches the tenant-owned channel and
provider message identifier. PostgreSQL prevents duplicate provider message
identifiers within a tenant and provider.

Failed messages remain visible and can be manually returned to the durable
outbox. Retry clears the old provider identifier and delivery timestamps, then
uses the normal tenant-scoped worker checks before another send. The delivery
report shows channel capabilities explicitly: a plain `SENT` status is never
presented as delivered or read when the provider cannot supply that fact.
For a critical specialist-absence cancellation, affected clients remain in the
report even when no primary channel exists or no message could be queued.

## Telegram adapter (part 47)

`TelegramChannelProvider` sends text and inline callback/link keyboards through
the Bot API. The bot token is resolved from protected deployment configuration
at send time and is never stored in the outbox. Telegram `update_id` is the
stable provider event identifier. Callback queries become provider-neutral
`BUTTON` events; all ordinary text remains `TEXT_UNSUPPORTED`.

Telegram webhook authentication uses the documented
`X-Telegram-Bot-Api-Secret-Token` value bound to the tenant-owned connection.
The `/start` deep link carries the existing expiring, single-use activation
token, and the worker replaces the provisional address with the verified
numeric Telegram user/chat identifier. Telegram exposes buttons and deep links,
but not delivery/read capabilities.

## WhatsApp adapter (part 48)

`WhatsAppChannelProvider` uses the versioned Cloud API messages endpoint. Access
token, sender phone-number ID, Graph API version, and Meta app secret are
deployment configuration and never durable message metadata. Text and up to
three documented reply buttons are supported. The accepted `wamid` is stored as
the provider message identifier so later `delivered`, `read`, and `failed`
webhooks can update the separate transport lifecycle monotonically.

POST webhooks require Meta's `X-Hub-Signature-256` HMAC over the exact raw body;
GET subscription verification compares the connection-bound verify token before
returning `hub.challenge`. Incoming reply buttons become `BUTTON` events, while
unknown delivery states are ignored. A `wa.me` prefilled activation message
contains `VOVREMYA_CONNECT {token}`, where `{token}` is expiring and single-use;
the verified sender `wa_id` becomes the connection address. Arbitrary text from
either bot is never interpreted as a command and receives the provider-neutral
instruction to use message buttons.
