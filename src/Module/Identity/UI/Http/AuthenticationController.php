<?php

declare(strict_types=1);

namespace App\Module\Identity\UI\Http;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AuthenticationController
{
    #[Route('/api/login', name: 'api_auth_login', methods: ['POST'])]
    public function loginCheck(): JsonResponse
    {
        return new JsonResponse(['message' => 'Authentication required.'], JsonResponse::HTTP_UNAUTHORIZED);
    }

    #[Route('/api/auth/csrf', name: 'api_auth_csrf', methods: ['GET'])]
    public function csrf(CsrfTokenManagerInterface $csrfTokenManager): JsonResponse
    {
        return new JsonResponse([
            'token' => $csrfTokenManager->getToken('authenticate')->getValue(),
            'logoutToken' => $csrfTokenManager->getToken('logout')->getValue(),
            'mutationToken' => $csrfTokenManager->getToken('mutation')->getValue(),
        ]);
    }

    #[Route('/api/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(#[CurrentUser] ?AdministratorAccount $administrator): JsonResponse
    {
        if (null === $administrator) {
            return new JsonResponse(['message' => 'Authentication required.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'id' => $administrator->id()->toRfc4122(),
            'email' => $administrator->email(),
            'organization' => [
                'id' => $administrator->organization()->id()->toRfc4122(),
                'name' => $administrator->organization()->name(),
                'timezone' => $administrator->organization()->timezone(),
                'defaultChannel' => $administrator->organization()->defaultChannel(),
            ],
        ]);
    }

    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function login(#[CurrentUser] ?AdministratorAccount $administrator): JsonResponse
    {
        if (null !== $administrator) {
            return new JsonResponse(['authenticated' => true]);
        }

        return new JsonResponse([
            'authenticated' => false,
            'csrfTokenEndpoint' => '/api/auth/csrf',
        ]);
    }
}
