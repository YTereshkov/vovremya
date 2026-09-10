<?php

declare(strict_types=1);

namespace App\Module\Communications\UI\Http;

use App\Module\Communications\Application\ConfirmationSettingsService;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/communications/confirmation-settings')]
final readonly class ConfirmationSettingsController
{
    private const FIELDS = [
        'requestTime', 'noResponseTime', 'reminderEnabled', 'reminderLeadMinutes',
        'reminderNotBefore', 'quietHoursStart', 'quietHoursEnd',
    ];

    public function __construct(private ConfirmationSettingsService $settings, private CsrfTokenManagerInterface $csrf)
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
            $input = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
            $keys = is_array($input) && !array_is_list($input) ? array_keys($input) : [];
            sort($keys);
            $expected = self::FIELDS;
            sort($expected);
            if (!is_array($input) || array_is_list($input) || $keys !== $expected) {
                throw new \InvalidArgumentException('Некорректные поля настроек подтверждений.');
            }

            return new JsonResponse($this->settings->present($this->settings->change($actor, $input)));
        } catch (\JsonException|\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage() ?: 'Некорректные настройки подтверждений.'], 422);
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
