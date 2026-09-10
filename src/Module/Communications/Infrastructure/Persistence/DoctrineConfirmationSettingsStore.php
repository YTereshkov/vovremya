<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Persistence;

use App\Module\Communications\Application\ConfirmationSettingsStore;
use App\Module\Communications\Domain\Model\ConfirmationSettings;
use App\Module\Organization\Application\OrganizationContext;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineConfirmationSettingsStore implements ConfirmationSettingsStore
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationContext $context)
    {
    }

    public function current(): ?ConfirmationSettings
    {
        $settings = $this->entityManager->createQueryBuilder()
            ->select('settings')
            ->from(ConfirmationSettings::class, 'settings')
            ->andWhere('IDENTITY(settings.organization) = :organization')
            ->setParameter('organization', $this->context->currentId(), 'ulid')
            ->getQuery()
            ->getOneOrNullResult();

        return $settings instanceof ConfirmationSettings ? $settings : null;
    }

    public function save(ConfirmationSettings $settings): void
    {
        if (!$settings->organizationId()->equals($this->context->currentId())) {
            throw new \LogicException('Cannot write confirmation settings outside current organization.');
        }
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
    }
}
