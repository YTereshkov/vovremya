# Communications foundation: part 28

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
original row and receive the same acknowledgement (`200` for MAX, `202` for
providers using the generic response).

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
