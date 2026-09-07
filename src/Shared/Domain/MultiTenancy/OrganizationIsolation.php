<?php

declare(strict_types=1);

namespace App\Shared\Domain\MultiTenancy;

use Symfony\Component\Uid\Ulid;

final class OrganizationIsolation
{
    public static function belongsTo(Ulid $organizationId, OrganizationOwned ...$resources): bool
    {
        foreach ($resources as $resource) {
            if (!$organizationId->equals($resource->organizationId())) {
                return false;
            }
        }

        return true;
    }

    public static function assertCanAssociate(
        OrganizationOwned $owner,
        OrganizationOwned ...$relatedResources,
    ): void {
        if (!self::belongsTo($owner->organizationId(), ...$relatedResources)) {
            throw new CrossOrganizationAssociation();
        }
    }
}
