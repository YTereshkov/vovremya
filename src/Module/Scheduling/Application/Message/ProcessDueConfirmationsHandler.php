<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application\Message;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Organization\Application\OrganizationDirectory;
use App\Module\Scheduling\Application\ConfirmationAutomation;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessDueConfirmationsHandler
{
    public function __construct(
        private OrganizationDirectory $organizations,
        private OrganizationContext $context,
        private ConfirmationAutomation $automation,
    ) {
    }

    public function __invoke(ProcessDueConfirmations $message): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($this->organizations->allIds() as $organizationId) {
            $this->context->runWith($organizationId, function () use ($organizationId, $now): void {
                $organization = $this->organizations->find($organizationId);
                if (null !== $organization) {
                    $this->automation->process($organization, $now);
                }
            });
        }
    }
}
