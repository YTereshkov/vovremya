<?php

declare(strict_types=1);

namespace App\Module\Waiting\UI\Http;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Waiting\Application\WaitingListManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class WaitingListController
{
    public function __construct(private WaitingListManager $waiting, private CsrfTokenManagerInterface $csrf)
    {
    }

    #[Route('/api/clients/{clientId}/waiting-list', methods: ['GET'])]
    public function show(string $clientId): JsonResponse
    {
        try {
            $entry = $this->waiting->activeForClient($clientId);

            return null === $entry ? JsonResponse::fromJsonString('null') : new JsonResponse($entry);
        } catch (\OutOfBoundsException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 404);
        }
    }

    #[Route('/api/clients/{clientId}/waiting-list', methods: ['PUT'])]
    public function save(string $clientId, Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        return $this->respond(function () use ($clientId, $request, $actor): array {
            $data = $this->body($request);

            return $this->waiting->save(
                $actor,
                $clientId,
                $this->string($data, 'serviceId'),
                $this->optionalString($data, 'specialistId'),
                $this->integer($data, 'requiredFrequency'),
                $this->boolean($data, 'readyForOneOff'),
                $this->optionalString($data, 'comment'),
                $this->availability($data),
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
        });
    }

    #[Route('/api/clients/{clientId}/waiting-list', methods: ['DELETE'])]
    public function end(string $clientId, Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        return $this->respond(function () use ($clientId, $request, $actor): null {
            $this->checkCsrf($request);
            $this->waiting->end($actor, $clientId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

            return null;
        }, 204);
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $this->checkCsrf($request);
        try {
            $data = json_decode($request->getContent(), true, 24, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Некорректный JSON.');
        }
        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), ['serviceId', 'specialistId', 'requiredFrequency', 'readyForOneOff', 'comment', 'availability'])) {
            throw new \InvalidArgumentException('Некорректные поля запроса.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data
     *  @return list<array{weekday: int, startTime: string, endTime: ?string}>
     */
    private function availability(array $data): array
    {
        $items = $data['availability'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new \InvalidArgumentException('Некорректные подходящие дни.');
        }

        return array_map(function (mixed $item): array {
            if (!is_array($item) || array_is_list($item) || array_diff(array_keys($item), ['weekday', 'startTime', 'endTime'])) {
                throw new \InvalidArgumentException('Некорректный подходящий день.');
            }

            return [
                'weekday' => $this->integer($item, 'weekday'),
                'startTime' => $this->string($item, 'startTime'),
                'endTime' => $this->optionalString($item, 'endTime'),
            ];
        }, $items);
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        if (!is_string($data[$key] ?? null)) {
            throw new \InvalidArgumentException('Заполните обязательные поля.');
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function optionalString(array $data, string $key): ?string
    {
        if (null !== ($data[$key] ?? null) && !is_string($data[$key])) {
            throw new \InvalidArgumentException('Некорректное текстовое поле.');
        }

        return $data[$key] ?? null;
    }

    /** @param array<string, mixed> $data */
    private function integer(array $data, string $key): int
    {
        if (!is_int($data[$key] ?? null)) {
            throw new \InvalidArgumentException('Некорректное числовое поле.');
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function boolean(array $data, string $key): bool
    {
        if (!is_bool($data[$key] ?? null)) {
            throw new \InvalidArgumentException('Некорректный переключатель.');
        }

        return $data[$key];
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('mutation', $request->headers->get('X-CSRF-Token')))) {
            throw new \UnexpectedValueException('Сессия формы устарела. Обновите страницу.');
        }
    }

    private function respond(callable $operation, int $status = 200): JsonResponse
    {
        try {
            return new JsonResponse($operation(), $status);
        } catch (\OutOfBoundsException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 404);
        } catch (\UnexpectedValueException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 422);
        } catch (\DomainException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 409);
        }
    }
}
