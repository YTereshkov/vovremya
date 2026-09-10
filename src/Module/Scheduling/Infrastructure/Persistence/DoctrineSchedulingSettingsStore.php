<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Scheduling\Application\SchedulingSettingsStore;
use App\Module\Scheduling\Domain\Model\SchedulingSettings;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSchedulingSettingsStore implements SchedulingSettingsStore
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationContext $context)
    {
    }

    public function current(): ?SchedulingSettings
    {
        $settings = $this->entityManager->createQueryBuilder()->select('settings')->from(SchedulingSettings::class, 'settings')
            ->andWhere('IDENTITY(settings.organization) = :organization')->setParameter('organization', $this->context->currentId(), 'ulid')
            ->getQuery()->getOneOrNullResult();

        return $settings instanceof SchedulingSettings ? $settings : null;
    }

    public function save(SchedulingSettings $settings): void
    {
        if (!$settings->organizationId()->equals($this->context->currentId())) {
            throw new \LogicException('Cannot write scheduling settings outside current organization.');
        }
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
    }
}
