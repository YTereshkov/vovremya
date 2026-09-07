<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Persistence;

use App\Module\Identity\Application\AdministratorAccountReader;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Application\OrganizationContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineAdministratorAccountReader implements AdministratorAccountReader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrganizationContext $organizationContext,
    ) {
    }

    public function findInCurrentOrganization(Ulid $administratorId): ?AdministratorAccount
    {
        $result = $this->entityManager
            ->createQueryBuilder()
            ->select('administrator')
            ->from(AdministratorAccount::class, 'administrator')
            ->andWhere('administrator.id = :administratorId')
            ->andWhere('IDENTITY(administrator.organization) = :organizationId')
            ->setParameter('administratorId', $administratorId, 'ulid')
            ->setParameter('organizationId', $this->organizationContext->currentId(), 'ulid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof AdministratorAccount ? $result : null;
    }
}
