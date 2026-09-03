<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Persistence;

use App\Module\Identity\Application\AdministratorProvisioningStore;
use App\Module\Identity\Domain\Exception\DuplicateAdministratorEmail;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Domain\Model\Organization;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAdministratorProvisioningStore implements AdministratorProvisioningStore
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function emailExists(string $normalizedEmail): bool
    {
        return 0 < $this->entityManager
            ->getRepository(AdministratorAccount::class)
            ->count(['normalizedEmail' => $normalizedEmail]);
    }

    public function save(Organization $organization, AdministratorAccount $administrator): void
    {
        try {
            $this->entityManager->wrapInTransaction(function () use ($organization, $administrator): void {
                $this->entityManager->persist($organization);
                $this->entityManager->persist($administrator);
                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new DuplicateAdministratorEmail($administrator->normalizedEmail(), $exception);
        }
    }
}
