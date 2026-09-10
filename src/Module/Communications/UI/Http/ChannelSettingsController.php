<?php

declare(strict_types=1);

namespace App\Module\Communications\UI\Http;

use App\Module\Communications\Application\ChannelProviderRegistry;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Application\OrganizationDirectory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/communications/channels')]
final readonly class ChannelSettingsController
{
    public function __construct(
        private ChannelProviderRegistry $providers,
        private OrganizationDirectory $organizations,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function settings(#[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        return new JsonResponse([
            'defaultProvider' => $actor->organization()->defaultChannel(),
            'providers' => array_map(static function ($provider): array {
                $capabilities = $provider->capabilities();

                return [
                    'provider' => $provider->provider()->value,
                    'capabilities' => [
                        'supportsButtons' => $capabilities->supportsButtons,
                        'supportsMessageEdit' => $capabilities->supportsMessageEdit,
                        'supportsDeliveredStatus' => $capabilities->supportsDeliveredStatus,
                        'supportsReadStatus' => $capabilities->supportsReadStatus,
                        'supportsDeepLink' => $capabilities->supportsDeepLink,
                    ],
                ];
            }, $this->providers->all()),
        ]);
    }

    #[Route('/default', methods: ['PUT'])]
    public function changeDefault(Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        try {
            $this->checkCsrf($request);
            $data = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_keys($data) !== ['provider'] || !is_string($data['provider'])) {
                throw new \InvalidArgumentException('Некорректные поля запроса.');
            }
            $provider = CommunicationProvider::from(strtoupper($data['provider']));
            $this->providers->get($provider);
            $actor->organization()->changeDefaultChannel($provider->value);
            $this->organizations->save($actor->organization());

            return $this->settings($actor);
        } catch (\JsonException|\ValueError|\InvalidArgumentException|\DomainException $e) {
            return new JsonResponse(['message' => $e->getMessage() ?: 'Выберите доступный канал.'], 422);
        } catch (\UnexpectedValueException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 403);
        }
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('mutation', $request->headers->get('X-CSRF-Token')))) {
            throw new \UnexpectedValueException('Сессия формы устарела. Обновите страницу.');
        }
    }
}
