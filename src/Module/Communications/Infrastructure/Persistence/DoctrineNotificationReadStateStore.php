<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Persistence;

use App\Module\Communications\Application\NotificationReadStateStore;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Application\OrganizationContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineNotificationReadStateStore implements NotificationReadStateStore
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationContext $context)
    {
    }

    public function markReadThrough(AdministratorAccount $administrator, \DateTimeImmutable $at): \DateTimeImmutable
    {
        if (!$administrator->organizationId()->equals($this->context->currentId())) {
            throw new \LogicException('Cannot write notification state outside current organization.');
        }
        $at = $at->setTimezone(new \DateTimeZone('UTC'));
        $this->entityManager->getConnection()->executeStatement(<<<'SQL'
            INSERT INTO administrator_notification_states (id, organization_id, administrator_id, read_through, updated_at)
            VALUES (:id, :organization, :administrator, :read_through, :updated_at)
            ON CONFLICT (organization_id, administrator_id) DO UPDATE
            SET read_through = GREATEST(administrator_notification_states.read_through, EXCLUDED.read_through),
                updated_at = GREATEST(administrator_notification_states.updated_at, EXCLUDED.updated_at)
            SQL, [
                'id' => (new Ulid())->toRfc4122(),
                'organization' => $administrator->organizationId()->toRfc4122(),
                'administrator' => $administrator->id()->toRfc4122(),
                'read_through' => $at->format('Y-m-d H:i:s.uP'),
                'updated_at' => $at->format('Y-m-d H:i:s.uP'),
            ]);

        return $at;
    }
}
