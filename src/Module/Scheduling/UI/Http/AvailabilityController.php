<?php

declare(strict_types=1);

namespace App\Module\Scheduling\UI\Http;

use App\Module\Scheduling\Application\AvailabilityService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class AvailabilityController
{
    public function __construct(
        private AvailabilityService $availability,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/api/availability/check', methods: ['POST'])]
    public function check(Request $request): JsonResponse
    {
        try {
            $this->checkCsrf($request);
            $data = $this->body($request);
            $decision = $this->availability->check(
                $this->specialistId($data),
                $this->instant($data, 'startsAt'),
                $this->instant($data, 'endsAt'),
            );

            return new JsonResponse($decision->toArray(), $decision->available ? 200 : 409);
        } catch (\OutOfBoundsException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 404);
        } catch (\UnexpectedValueException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 422);
        }
    }

    /** @return array{specialistId: mixed, startsAt: mixed, endsAt: mixed} */
    private function body(Request $request): array
    {
        try {
            $data = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Некорректный JSON.');
        }

        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), ['specialistId', 'startsAt', 'endsAt'])) {
            throw new \InvalidArgumentException('Некорректные поля запроса.');
        }

        return [
            'specialistId' => $data['specialistId'] ?? null,
            'startsAt' => $data['startsAt'] ?? null,
            'endsAt' => $data['endsAt'] ?? null,
        ];
    }

    /** @param array<string, mixed> $data */
    private function specialistId(array $data): Ulid
    {
        if (!is_string($data['specialistId'])) {
            throw new \InvalidArgumentException('Укажите специалиста.');
        }

        try {
            return Ulid::fromString($data['specialistId']);
        } catch (\InvalidArgumentException) {
            throw new \OutOfBoundsException('Специалист не найден.');
        }
    }

    /** @param array<string, mixed> $data */
    private function instant(array $data, string $key): \DateTimeImmutable
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || !preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            throw new \InvalidArgumentException('Укажите время в ISO 8601 с часовым поясом.');
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException('Укажите корректные дату и время.');
        }
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('mutation', $request->headers->get('X-CSRF-Token')))) {
            throw new \UnexpectedValueException('Сессия формы устарела. Обновите страницу.');
        }
    }
}
