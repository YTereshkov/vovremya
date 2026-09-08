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
original row and receive the same `202` acknowledgement.

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
