<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

final class AuthenticationSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): JsonResponse
    {
        $user = $token->getUser();

        if (!$user instanceof AdministratorAccount) {
            return new JsonResponse(['message' => 'Authenticated user is invalid.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'authenticated' => true,
            'email' => $user->email(),
            'organization' => $user->organization()->name(),
        ]);
    }
}
