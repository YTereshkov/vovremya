<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Communications\Application\ConfirmationSettingsService;
use App\Module\Organization\Domain\Model\Organization;

final readonly class ConfirmationAutomation
{
    public function __construct(
        private AppointmentConfirmationStore $store,
        private AppointmentConfirmationService $confirmations,
        private ConfirmationSettingsService $settingsService,
    ) {
    }

    public function process(Organization $organization, \DateTimeImmutable $now): void
    {
        $utcNow = $now->setTimezone(new \DateTimeZone('UTC'));
        $timezone = new \DateTimeZone($organization->timezone());
        $localNow = $utcNow->setTimezone($timezone);
        $settings = $this->settingsService->forOrganization($organization);
        $appointments = $this->store->futureAppointments($utcNow, $utcNow->modify('+3 days'));

        foreach ($appointments as $appointment) {
            $localStart = $appointment->startsAt()->setTimezone($timezone);
            $requestAt = new \DateTimeImmutable($localStart->modify('-1 day')->format('Y-m-d').' '.$settings->requestTime(), $timezone);
            $noResponseAt = new \DateTimeImmutable($localStart->modify('-1 day')->format('Y-m-d').' '.$settings->noResponseTime(), $timezone);
            $request = $this->store->findByAppointment($appointment->id());

            if (null === $request && $localNow >= $requestAt && !$settings->isQuietAt($localNow)) {
                try {
                    $request = $this->confirmations->request($appointment->id(), $utcNow);
                } catch (\DomainException) {
                    // Missing or disabled client channel is retried by the next scheduled scan.
                }
            }
            if (null === $request) {
                continue;
            }
            if ($localNow >= $noResponseAt && \App\Module\Scheduling\Domain\Model\AppointmentConfirmationStatus::Pending === $request->status()) {
                $this->confirmations->markNoResponse($appointment, $request, $utcNow);
            }
            if (!$settings->reminderEnabled() || null !== $request->reminderSentAt() || $settings->isQuietAt($localNow)
                || !in_array($request->status(), [\App\Module\Scheduling\Domain\Model\AppointmentConfirmationStatus::Pending, \App\Module\Scheduling\Domain\Model\AppointmentConfirmationStatus::NoResponse], true)) {
                continue;
            }
            $leadAt = $localStart->modify(sprintf('-%d minutes', $settings->reminderLeadMinutes()));
            $notBefore = new \DateTimeImmutable($localStart->format('Y-m-d').' '.$settings->reminderNotBefore(), $timezone);
            $reminderAt = $leadAt > $notBefore ? $leadAt : $notBefore;
            if ($localNow >= $reminderAt) {
                try {
                    $this->confirmations->remind($appointment, $request, $utcNow);
                } catch (\DomainException) {
                    // A disconnected original channel remains visible for operational handling.
                }
            }
        }
    }
}
