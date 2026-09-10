<?php

declare(strict_types=1);

namespace App\Module\Scheduling\UI\Http;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Application\SchedulingSettingsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/scheduling/settings')]
final readonly class SchedulingSettingsController
{
    public function __construct(private SchedulingSettingsService $settings, private CsrfTokenManagerInterface $csrf)
    {
    }

    #[Route('', methods: ['GET'])]
    public function get(#[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        return new JsonResponse($this->settings->present($this->settings->forOrganization($actor->organization())));
    }

    #[Route('', methods: ['PUT'])]
    public function change(Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        try {
            $this->checkCsrf($request);
            $data = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_keys($data) !== ['lateCancellationHours'] || !is_int($data['lateCancellationHours'])) {
                throw new \InvalidArgumentException('Некорректные настройки расписания.');
            }

            return new JsonResponse($this->settings->present($this->settings->change($actor, $data['lateCancellationHours'])));
        } catch (\JsonException|\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage() ?: 'Некорректные настройки расписания.'], 422);
        } catch (\UnexpectedValueException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        }
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('mutation', $request->headers->get('X-CSRF-Token')))) {
            throw new \UnexpectedValueException('Сессия формы устарела. Обновите страницу.');
        }
    }
}
