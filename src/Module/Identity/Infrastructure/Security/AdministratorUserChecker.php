<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class AdministratorUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof AdministratorAccount && !$user->isEnabled()) {
            throw new CustomUserMessageAuthenticationException('This administrator account is disabled.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
