<?php

declare(strict_types=1);

namespace App\Module\Communications\UI\Http;

use App\Module\Communications\Application\NotificationReadService;
use App\Module\Communications\Application\OutboundMessageRetryService;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Ulid;

final readonly class NotificationMutationController
{
    public function __construct(
        private NotificationReadService $readState,
        private OutboundMessageRetryService $retry,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/api/notifications/read-all', methods: ['PUT'])]
    public function readAll(Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        try {
            $this->checkCsrf($request);
            $readThrough = $this->readState->markAllRead($actor, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

            return new JsonResponse(['readThrough' => $readThrough->format(DATE_ATOM)]);
        } catch (\UnexpectedValueException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        }
    }

    #[Route('/api/communications/messages/{id}/retry', methods: ['POST'])]
    public function retry(string $id, Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        try {
            $this->checkCsrf($request);
            $message = $this->retry->retry($actor, Ulid::fromString($id), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

            return new JsonResponse(['id' => $message->id()->toRfc4122(), 'status' => $message->status()->value]);
        } catch (\InvalidArgumentException|\OutOfBoundsException) {
            return new JsonResponse(['message' => 'Сообщение не найдено.'], 404);
        } catch (\UnexpectedValueException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        } catch (\DomainException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 409);
        }
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('mutation', $request->headers->get('X-CSRF-Token')))) {
            throw new \UnexpectedValueException('Сессия формы устарела. Обновите страницу.');
        }
    }
}
