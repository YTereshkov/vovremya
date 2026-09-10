<?php

declare(strict_types=1);

namespace App\Module\Organization\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationDirectory;
use App\Module\Organization\Domain\Model\Organization;
use App\Module\Organization\Application\OrganizationContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineOrganizationDirectory implements OrganizationDirectory
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationContext $organizationContext)
    {
    }

    public function allIds(): array
    {
        $rows = $this->entityManager->createQueryBuilder()->select('organization.id')->from(Organization::class, 'organization')
            ->orderBy('organization.id', 'ASC')->getQuery()->getScalarResult();

        return array_map(static fn (array $row): Ulid => Ulid::fromString((string) $row['id']), $rows);
    }

    public function save(Organization $organization): void
    {
        if (!$organization->id()->equals($this->organizationContext->currentId())) {
            throw new \LogicException('Cannot write organization outside the current tenant.');
        }
        $this->entityManager->persist($organization);
        $this->entityManager->flush();
    }
}
