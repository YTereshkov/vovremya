<?php

declare(strict_types=1);

namespace App\Module\Waiting\UI\Http;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Domain\TimeUnavailable;
use App\Module\Waiting\Application\FreeWindowOfferService;
use App\Module\Waiting\Domain\Model\FreeWindowOfferTargetType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Ulid;

final readonly class FreeWindowOfferController
{
    public function __construct(private FreeWindowOfferService $offers, private CsrfTokenManagerInterface $csrf)
    {
    }

    #[Route('/api/free-windows/{id}/offers', methods: ['POST'])]
    public function create(string $id, Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        try {
            $this->checkCsrf($request);
            $data = $this->body($request);
            if (!is_string($data['targetType'] ?? null) || !is_string($data['candidateId'] ?? null)) {
                throw new \InvalidArgumentException('Некорректный получатель предложения.');
            }
            $offer = $this->offers->create(
                $actor,
                Ulid::fromString($id),
                FreeWindowOfferTargetType::from($data['targetType']),
                Ulid::fromString($data['candidateId']),
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );

            return new JsonResponse($this->offers->present($offer), 201);
        } catch (TimeUnavailable $exception) {
            return new JsonResponse(['message' => $exception->getMessage(), 'conflict' => $exception->conflict->toArray()], 409);
        } catch (\OutOfBoundsException) {
            return new JsonResponse(['message' => 'Свободное окно не найдено.'], 404);
        } catch (\UnexpectedValueException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        } catch (\ValueError|\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 422);
        } catch (\DomainException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 409);
        }
    }

    #[Route('/api/free-window-offers/{id}', methods: ['DELETE'])]
    public function cancel(string $id, Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        try {
            $this->checkCsrf($request);
            $this->offers->cancel($actor, Ulid::fromString($id), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

            return new JsonResponse(null, 204);
        } catch (\InvalidArgumentException|\OutOfBoundsException) {
            return new JsonResponse(['message' => 'Предложение не найдено.'], 404);
        } catch (\UnexpectedValueException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        } catch (\DomainException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 409);
        }
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        try {
            $data = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Некорректный JSON.');
        }
        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), ['targetType', 'candidateId'])) {
            throw new \InvalidArgumentException('Некорректные поля запроса.');
        }

        return $data;
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('mutation', $request->headers->get('X-CSRF-Token')))) {
            throw new \UnexpectedValueException('Сессия формы устарела. Обновите страницу.');
        }
    }
}
