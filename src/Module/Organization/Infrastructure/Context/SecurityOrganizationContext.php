<?php

declare(strict_types=1);

namespace App\Module\Organization\Infrastructure\Context;

use App\Module\Organization\Application\Exception\CrossOrganizationContext;
use App\Module\Organization\Application\Exception\MissingOrganizationContext;
use App\Module\Organization\Application\OrganizationContext;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Service\ResetInterface;

final class SecurityOrganizationContext implements OrganizationContext, ResetInterface
{
    private ?Ulid $scopedOrganizationId = null;

    public function __construct(private readonly TokenStorageInterface $tokenStorage)
    {
    }

    public function currentId(): Ulid
    {
        return $this->currentIdOrNull() ?? throw new MissingOrganizationContext();
    }

    public function currentIdOrNull(): ?Ulid
    {
        if (null !== $this->scopedOrganizationId) {
            return $this->scopedOrganizationId;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof OrganizationOwned ? $user->organizationId() : null;
    }

    public function runWith(Ulid $organizationId, callable $operation): mixed
    {
        $currentOrganizationId = $this->currentIdOrNull();

        if (null !== $currentOrganizationId && !$currentOrganizationId->equals($organizationId)) {
            throw new CrossOrganizationContext();
        }

        $previousOrganizationId = $this->scopedOrganizationId;
        $this->scopedOrganizationId = $organizationId;

        try {
            return $operation();
        } finally {
            $this->scopedOrganizationId = $previousOrganizationId;
        }
    }

    public function reset(): void
    {
        $this->scopedOrganizationId = null;
    }
}
