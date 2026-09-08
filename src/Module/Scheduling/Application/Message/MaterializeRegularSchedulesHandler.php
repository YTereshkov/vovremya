<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application\Message;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Organization\Application\OrganizationDirectory;
use App\Module\Scheduling\Application\RegularScheduleManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class MaterializeRegularSchedulesHandler
{
    public function __construct(
        private OrganizationDirectory $organizations,
        private OrganizationContext $organizationContext,
        private RegularScheduleManager $manager,
    ) {
    }

    public function __invoke(MaterializeRegularSchedules $message): void
    {
        foreach ($this->organizations->allIds() as $organizationId) {
            $this->organizationContext->runWith($organizationId, function (): void {
                $this->manager->materializeAll(new \DateTimeImmutable('now'));
            });
        }
    }
}
