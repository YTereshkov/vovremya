<?php

declare(strict_types=1);

namespace App\Module\Waiting\UI\Http;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Waiting\Application\FreeWindowMatchingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class FreeWindowMatchingController
{
    public function __construct(private FreeWindowMatchingService $matching)
    {
    }

    #[Route('/api/free-windows/{id}/candidates', methods: ['GET'])]
    public function candidates(string $id, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        try {
            return new JsonResponse($this->matching->candidates($id, $actor->organization(), new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        } catch (\OutOfBoundsException) {
            return new JsonResponse(['message' => 'Свободное окно не найдено.'], 404);
        }
    }
}
