<?php

declare(strict_types=1);

namespace App\Module\Identity\UI\Http;

use App\Module\Identity\Application\AdministratorAccountReader;
use App\Module\Organization\Application\OrganizationPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Ulid;

final class AdministratorAccountController
{
    #[Route('/api/administrators/{id}', name: 'api_administrator_show', methods: ['GET'])]
    public function show(
        string $id,
        AdministratorAccountReader $administratorAccountReader,
        AuthorizationCheckerInterface $authorizationChecker,
    ): JsonResponse {
        try {
            $administrator = $administratorAccountReader->findInCurrentOrganization(Ulid::fromString($id));
        } catch (InvalidArgumentException) {
            $administrator = null;
        }

        if (null === $administrator || !$authorizationChecker->isGranted(OrganizationPermission::VIEW, $administrator)) {
            return new JsonResponse(['message' => 'Administrator not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'id' => $administrator->id()->toRfc4122(),
            'email' => $administrator->email(),
            'organizationId' => $administrator->organizationId()->toRfc4122(),
        ]);
    }
}
