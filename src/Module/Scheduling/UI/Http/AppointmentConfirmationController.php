<?php

declare(strict_types=1);

namespace App\Module\Scheduling\UI\Http;

use App\Module\Scheduling\Application\AppointmentConfirmationService;
use App\Module\Scheduling\Application\AppointmentConfirmationStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class AppointmentConfirmationController
{
    public function __construct(
        private AppointmentConfirmationService $confirmations,
        private AppointmentConfirmationStore $store,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/api/appointments/{id}/confirmation', methods: ['GET'])]
    public function status(string $id): JsonResponse
    {
        try {
            return new JsonResponse($this->confirmations->status(Ulid::fromString($id)));
        } catch (\InvalidArgumentException|\OutOfBoundsException) {
            return new JsonResponse(['message' => 'Занятие не найдено.'], 404);
        }
    }

    #[Route('/api/appointments/{id}/confirmation', methods: ['POST'])]
    public function request(string $id, Request $request): JsonResponse
    {
        try {
            $this->checkCsrf($request);
            $confirmation = $this->confirmations->request(
                Ulid::fromString($id),
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );

            return new JsonResponse([
                'status' => $confirmation->status()->value,
                'requestId' => $confirmation->id()->toRfc4122(),
            ], 201);
        } catch (\InvalidArgumentException|\OutOfBoundsException) {
            return new JsonResponse(['message' => 'Занятие не найдено.'], 404);
        } catch (\UnexpectedValueException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        } catch (\DomainException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 409);
        }
    }

    #[Route('/api/communications/confirmation-attention', methods: ['GET'])]
    public function attention(): JsonResponse
    {
        return new JsonResponse($this->store->noResponseAttention(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('mutation', $request->headers->get('X-CSRF-Token')))) {
            throw new \UnexpectedValueException('Сессия формы устарела. Обновите страницу.');
        }
    }
}
