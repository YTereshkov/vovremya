<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TenantMappingTest extends KernelTestCase
{
    #[Test]
    public function organizationOwnedEntitiesHaveRequiredTenantMapping(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            $hasOrganizationAssociation = $metadata->hasAssociation('organization');
            $implementsOrganizationOwned = is_a($metadata->getName(), OrganizationOwned::class, true);

            self::assertSame(
                $hasOrganizationAssociation,
                $implementsOrganizationOwned,
                $metadata->getName().' must map organization ownership consistently.',
            );

            if (!$implementsOrganizationOwned) {
                continue;
            }

            $hasCompositeTenantKey = false;

            foreach ($metadata->table['uniqueConstraints'] ?? [] as $constraint) {
                $columns = $constraint['columns'];
                sort($columns);

                if (['id', 'organization_id'] === $columns) {
                    $hasCompositeTenantKey = true;
                    break;
                }
            }

            self::assertTrue(
                $hasCompositeTenantKey,
                $metadata->getName().' must expose a unique (organization_id, id) key.',
            );

            foreach ($metadata->associationMappings as $association) {
                if (!is_a($association->targetEntity, OrganizationOwned::class, true)) {
                    continue;
                }

                self::assertFalse(
                    $association->isManyToManyOwningSide(),
                    $metadata->getName().' must model tenant many-to-many relations as explicit entities.',
                );

                if (!$association->isToOneOwningSide()) {
                    continue;
                }

                self::assertSame(
                    'organization_id',
                    $association->sourceToTargetKeyColumns['organization_id'] ?? null,
                    $metadata->getName().' tenant relation must include organization_id in its foreign key.',
                );
            }
        }
    }
}
