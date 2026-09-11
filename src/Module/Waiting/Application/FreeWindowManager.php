<?php

declare(strict_types=1);

namespace App\Module\Waiting\Application;

use App\Module\Organization\Domain\Model\Organization;
use App\Module\Scheduling\Application\AvailabilityService;
use App\Module\Waiting\Domain\Model\FreeWindow;
use App\Module\Waiting\Domain\Model\FreeWindowStatus;
use Symfony\Component\Uid\Ulid;

final readonly class FreeWindowManager
{
    public function __construct(
        private FreeWindowStore $windows,
        private FreeWindowOfferStore $offers,
        private FreeWindowOfferService $offerService,
        private AvailabilityService $availability,
    ) {
    }

    public function createFromCancellation(
        Organization $organization,
        Ulid $appointmentId,
        Ulid $specialistId,
        Ulid $serviceId,
        string $serviceName,
        int $durationMinutes,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        \DateTimeImmutable $now,
    ): FreeWindow {
        if ($startsAt <= $now) {
            throw new \DomainException('Свободное окно можно создать только для будущего занятия.');
        }
        $window = $this->windows->findBySourceAppointment($appointmentId);
        if (null !== $window) {
            $window->reopen();
        } else {
            $window = FreeWindow::create($organization, $appointmentId, $specialistId, $serviceId, $serviceName, $durationMinutes, $startsAt, $endsAt, $now);
        }
        $this->windows->save($window);

        return $window;
    }

    public function closeForAppointment(Ulid $appointmentId, string $reason, \DateTimeImmutable $now): void
    {
        $window = $this->windows->findBySourceAppointment($appointmentId);
        if (null === $window) {
            return;
        }
        $this->offerService->cancelActiveForWindow($window->id(), $reason, $now);
        $window->close($reason, $now);
        $this->windows->save($window);
    }

    public function isOpenForAppointment(Ulid $appointmentId): bool
    {
        return FreeWindowStatus::Open === $this->windows->findBySourceAppointment($appointmentId)?->status();
    }

    /** @return list<FreeWindow> */
    public function openFuture(\DateTimeImmutable $now): array
    {
        $windows = $this->windows->openFuture($now);
        $activeOffers = $this->offers->activeForWindows(array_map(static fn (FreeWindow $window): Ulid => $window->id(), $windows));

        return array_values(array_filter(
            $windows,
            fn (FreeWindow $window): bool => isset($activeOffers[$window->id()->toRfc4122()]) || $this->isAvailable($window),
        ));
    }

    private function isAvailable(FreeWindow $window): bool
    {
        try {
            return $this->availability->check($window->specialistId(), $window->startsAt(), $window->endsAt())->available;
        } catch (\OutOfBoundsException) {
            return false;
        }
    }
}
