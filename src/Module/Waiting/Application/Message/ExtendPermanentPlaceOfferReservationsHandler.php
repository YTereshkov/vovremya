<?php

declare(strict_types=1);

namespace App\Module\Waiting\Application\Message;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Organization\Application\OrganizationDirectory;
use App\Module\Waiting\Application\PermanentPlaceOfferService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExtendPermanentPlaceOfferReservationsHandler
{
    public function __construct(
        private OrganizationDirectory $organizations,
        private OrganizationContext $context,
        private PermanentPlaceOfferService $offers,
    ) {
    }

    public function __invoke(ExtendPermanentPlaceOfferReservations $message): void
    {
        foreach ($this->organizations->allIds() as $organizationId) {
            $this->context->runWith($organizationId, fn () => $this->offers->extendActive(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        }
    }
}
