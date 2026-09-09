<?php

declare(strict_types=1);

namespace App\Module\Communications\UI\Http;

use App\Module\Communications\Application\ChannelProviderRegistry;
use App\Module\Communications\Application\WebhookAuthenticationRequest;
use App\Module\Communications\Application\WebhookInboxRecorder;
use App\Module\Communications\Application\WebhookEventIdExtractor;
use App\Module\Clients\Application\ChannelConnectionResolver;
use App\Module\Communications\Application\Message\ProcessWebhookInbox;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Organization\Application\OrganizationContext;
use App\Module\Organization\Application\OrganizationExistenceChecker;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;

#[Route('/webhooks/communications/{provider}/{routingKey}', methods: ['POST'])]
final readonly class WebhookController
{
    private const MAX_PAYLOAD_BYTES = 262_144;

    public function __construct(
        private WebhookInboxRecorder $recorder,
        private ChannelProviderRegistry $providers,
        private OrganizationContext $organizationContext,
        private OrganizationExistenceChecker $organizations,
        private ChannelConnectionResolver $connections,
        private MessageBusInterface $bus,
        #[Autowire(service: 'limiter.communication_webhooks')]
        private RateLimiterFactoryInterface $communicationWebhooksLimiter,
    ) {
    }

    #[Route('', methods: ['POST'])]
    public function receive(string $provider, string $routingKey, Request $request): JsonResponse
    {
        try {
            $provider = CommunicationProvider::from(strtoupper($provider));
            $channelConnectionId = null;
            $connection = null;
            if (CommunicationProvider::MAX === $provider) {
                $connection = $this->connections->findChannelByRoutingKey($provider->value, $routingKey);
                if (null === $connection || null === $connection->webhookSecretHash() || (!$connection->isActive() && !$connection->isPendingActivation())) {
                    return new JsonResponse(['message' => 'Webhook для этого подключения не найден.'], 404);
                }
                $organization = $connection->organizationId();
                $channelConnectionId = $connection->id();
            } else {
                $organization = Ulid::fromString($routingKey);
                if (!$this->organizations->exists($organization)) {
                    return new JsonResponse(['message' => 'Организация не найдена.'], 404);
                }
            }

            $rateLimit = $this->communicationWebhooksLimiter
                ->create(hash('sha256', $provider->value.'|'.$organization->toRfc4122().'|'.($request->getClientIp() ?? 'unknown')))
                ->consume();
            if (!$rateLimit->isAccepted()) {
                $retryAfter = max(1, $rateLimit->getRetryAfter()->getTimestamp() - time());

                return new JsonResponse(['message' => 'Слишком много запросов.'], 429, ['Retry-After' => (string) $retryAfter]);
            }

            $rawBody = $request->getContent();
            if (self::MAX_PAYLOAD_BYTES < strlen($rawBody)) {
                return new JsonResponse(['message' => 'Webhook превышает допустимый размер.'], 413);
            }
            try {
                $channelProvider = $this->providers->get($provider);
            } catch (\DomainException) {
                return new JsonResponse(['message' => 'Webhook для этого канала не настроен.'], 404);
            }
            $authenticated = $this->organizationContext->runWith(
                $organization,
                fn (): bool => $channelProvider->authenticateWebhook(new WebhookAuthenticationRequest(
                    $rawBody,
                    $request->headers->all(),
                    $channelConnectionId ? $connection->webhookSecretHash() : null,
                )),
            );
            if (!$authenticated) {
                return new JsonResponse(['message' => 'Не удалось проверить подлинность webhook.'], 401);
            }

            $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || array_is_list($payload)) {
                return new JsonResponse(['message' => 'Webhook должен содержать JSON-объект.'], 422);
            }

            // MAX event identity comes from the authenticated provider payload;
            // a caller-controlled generic header must never override it.
            $eventId = CommunicationProvider::MAX === $provider
                ? ''
                : trim((string) $request->headers->get('X-Webhook-Event-Id', ''));
            if (CommunicationProvider::MAX === $provider) {
                // MAX's stable update/callback identifier is used for inbox idempotency;
                // the raw envelope is only the final fallback for undocumented payloads.
                $eventIds = $channelProvider instanceof WebhookEventIdExtractor
                    ? $channelProvider->extractWebhookEventIds($payload)
                    : [];
                sort($eventIds);
                $eventId = match (count($eventIds)) {
                    0 => 'max-'.hash('sha256', $rawBody),
                    1 => $eventIds[0],
                    default => 'max-batch-'.hash('sha256', implode("\n", $eventIds)),
                };
            }
            if ('' === $eventId) {
                return new JsonResponse(['message' => 'Отсутствует идентификатор события webhook.'], 422);
            }

            /** @var array{inbox: \App\Module\Communications\Domain\Model\WebhookInbox, duplicate: bool} $result */
            $result = $this->organizationContext->runWith($organization, fn (): array => $this->recorder->receive($provider, $eventId, $payload, $channelConnectionId));

            try {
                $this->bus->dispatch(new ProcessWebhookInbox($organization, $result['inbox']->id()));
            } catch (TransportException) {
                // PostgreSQL remains the source of truth; the scheduler republishes unprocessed inbox rows.
            }

            // MAX requires a fast HTTP 200 acknowledgement; other providers keep the generic 202 response.
            $status = CommunicationProvider::MAX === $provider ? 200 : 202;

            return new JsonResponse(['accepted' => true, 'duplicate' => $result['duplicate']], $status);
        } catch (\ValueError|\JsonException|\InvalidArgumentException) {
            return new JsonResponse(['message' => 'Некорректный webhook.'], 422);
        }
    }
}
