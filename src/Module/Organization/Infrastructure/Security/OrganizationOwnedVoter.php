<?php

declare(strict_types=1);

namespace App\Module\Organization\Infrastructure\Security;

use App\Module\Organization\Application\OrganizationPermission;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class OrganizationOwnedVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof OrganizationOwned
            && in_array($attribute, [OrganizationPermission::VIEW, OrganizationPermission::EDIT], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return $user instanceof OrganizationOwned
            && $subject instanceof OrganizationOwned
            && OrganizationIsolation::belongsTo($user->organizationId(), $subject);
    }
}
