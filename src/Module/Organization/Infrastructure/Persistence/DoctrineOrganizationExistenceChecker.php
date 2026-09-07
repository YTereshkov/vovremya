<?php

declare(strict_types=1);

namespace App\Module\Organization\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationExistenceChecker;
use App\Module\Organization\Domain\Model\Organization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineOrganizationExistenceChecker implements OrganizationExistenceChecker
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function exists(Ulid $organizationId): bool
    {
        return 1 === $this->entityManager
            ->getRepository(Organization::class)
            ->count(['id' => $organizationId]);
    }
}
