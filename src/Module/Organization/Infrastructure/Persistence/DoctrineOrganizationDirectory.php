<?php

declare(strict_types=1);

namespace App\Module\Organization\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationDirectory;
use App\Module\Organization\Domain\Model\Organization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineOrganizationDirectory implements OrganizationDirectory
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function allIds(): array
    {
        $rows = $this->entityManager->createQueryBuilder()->select('organization.id')->from(Organization::class, 'organization')
            ->orderBy('organization.id', 'ASC')->getQuery()->getScalarResult();

        return array_map(static fn (array $row): Ulid => Ulid::fromString((string) $row['id']), $rows);
    }
}
