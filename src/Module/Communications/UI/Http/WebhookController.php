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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/webhooks/communications/{provider}/{routingKey}')]
final readonly class WebhookController
{
    private const MAX_PAYLOAD_BYTES = 262_144;

    public function __construct(
        private WebhookInboxRecorder $recorder,
        private ChannelProviderRegistry $providers,
        private OrganizationContext $organizationContext,
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
            $connection = $this->connections->findChannelByRoutingKey($provider->value, $routingKey);
            if (null === $connection || null === $connection->webhookSecretHash() || (!$connection->isActive() && !$connection->isPendingActivation())) {
                return new JsonResponse(['message' => 'Webhook для этого подключения не найден.'], 404);
            }
            $organization = $connection->organizationId();
            $channelConnectionId = $connection->id();

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

            // Event identity always comes from the authenticated provider payload.
            $eventIds = $channelProvider instanceof WebhookEventIdExtractor
                ? $channelProvider->extractWebhookEventIds($payload)
                : [];
            sort($eventIds);
            $eventId = match (count($eventIds)) {
                0 => strtolower($provider->value).'-'.hash('sha256', $rawBody),
                1 => $eventIds[0],
                default => strtolower($provider->value).'-batch-'.hash('sha256', implode("\n", $eventIds)),
            };
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

            return new JsonResponse(['accepted' => true, 'duplicate' => $result['duplicate']], 200);
        } catch (\ValueError|\JsonException|\InvalidArgumentException) {
            return new JsonResponse(['message' => 'Некорректный webhook.'], 422);
        }
    }

    #[Route('', methods: ['GET'])]
    public function verify(string $provider, string $routingKey, Request $request): Response
    {
        try {
            $provider = CommunicationProvider::from(strtoupper($provider));
            if (CommunicationProvider::WHATSAPP !== $provider || 'subscribe' !== $request->query->get('hub_mode')) {
                return new JsonResponse(['message' => 'Проверка webhook не поддерживается.'], 404);
            }
            $connection = $this->connections->findChannelByRoutingKey($provider->value, $routingKey);
            $token = $request->query->get('hub_verify_token');
            $challenge = $request->query->get('hub_challenge');
            if (null === $connection || !is_string($token) || !is_string($challenge) || !$connection->verifyWebhookSecret($token)) {
                return new JsonResponse(['message' => 'Не удалось проверить webhook.'], 401);
            }

            return new Response($challenge, 200, ['Content-Type' => 'text/plain']);
        } catch (\ValueError) {
            return new JsonResponse(['message' => 'Некорректный webhook.'], 422);
        }
    }
}
