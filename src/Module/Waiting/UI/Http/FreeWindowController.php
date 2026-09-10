<?php

declare(strict_types=1);

namespace App\Module\Waiting\UI\Http;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Waiting\Application\FreeWindowManager;
use App\Module\Waiting\Domain\Model\FreeWindow;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class FreeWindowController
{
    public function __construct(private FreeWindowManager $windows)
    {
    }

    #[Route('/api/free-windows', methods: ['GET'])]
    public function list(#[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        $timezone = new \DateTimeZone($actor->organization()->timezone());

        return new JsonResponse(array_map(static function (FreeWindow $window) use ($timezone): array {
            $start = $window->startsAt()->setTimezone($timezone);
            $end = $window->endsAt()->setTimezone($timezone);
            return [
                'id' => $window->id()->toRfc4122(),
                'sourceAppointmentId' => $window->sourceAppointmentId()->toRfc4122(),
                'specialistId' => $window->specialistId()->toRfc4122(),
                'service' => ['id' => $window->serviceId()->toRfc4122(), 'name' => $window->serviceName()],
                'durationMinutes' => $window->durationMinutes(),
                'date' => $start->format('Y-m-d'),
                'startTime' => $start->format('H:i'),
                'endTime' => $end->format('H:i'),
                'startsAt' => $start->format(\DateTimeInterface::RFC3339_EXTENDED),
                'endsAt' => $end->format(\DateTimeInterface::RFC3339_EXTENDED),
                'status' => $window->status()->value,
            ];
        }, $this->windows->openFuture(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))));
    }
}
