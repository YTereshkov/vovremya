<?php

declare(strict_types=1);

namespace App\Module\Scheduling\UI\Http;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Application\CalendarAppointment;
use App\Module\Scheduling\Application\CalendarQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Ulid;

final readonly class CalendarController
{
    public function __construct(private CalendarQuery $calendar)
    {
    }

    #[Route('/api/calendar', methods: ['GET'])]
    public function calendar(Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        try {
            $timezone = new \DateTimeZone($actor->organization()->timezone());
            $from = $this->date($request->query->get('from'), $timezone);
            $to = $this->date($request->query->get('to'), $timezone);
            if ($to < $from) {
                throw new \InvalidArgumentException('Конечная дата должна быть не раньше начальной.');
            }
            $until = $to->modify('+1 day');
            if (31 < (int) $from->diff($until)->days) {
                throw new \InvalidArgumentException('Диапазон календаря не должен превышать 31 день.');
            }
            $specialistId = $this->optionalId($request->query->get('specialistId'));
            $appointments = $this->calendar->between($from, $until, $specialistId);

            return new JsonResponse([
                'timezone' => $timezone->getName(),
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'appointments' => array_map(fn (CalendarAppointment $appointment): array => $this->present($appointment, $timezone), $appointments),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 422);
        }
    }

    #[Route('/api/appointments/{id}', methods: ['GET'])]
    public function appointment(string $id, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        try {
            $appointment = $this->calendar->find(Ulid::fromString($id));
        } catch (\InvalidArgumentException) {
            $appointment = null;
        }
        if (null === $appointment) {
            return new JsonResponse(['message' => 'Занятие не найдено.'], 404);
        }

        return new JsonResponse($this->present($appointment, new \DateTimeZone($actor->organization()->timezone())));
    }

    private function date(mixed $value, \DateTimeZone $timezone): \DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            throw new \InvalidArgumentException('Укажите даты календаря в формате YYYY-MM-DD.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Укажите корректные даты календаря.');
        }

        return $date;
    }

    private function optionalId(mixed $value): ?Ulid
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Некорректный специалист.');
        }

        try {
            return Ulid::fromString($value);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('Некорректный специалист.');
        }
    }

    /** @return array<string, mixed> */
    private function present(CalendarAppointment $appointment, \DateTimeZone $timezone): array
    {
        $startsAt = $appointment->startsAt->setTimezone($timezone);
        $endsAt = $appointment->endsAt->setTimezone($timezone);

        return [
            'id' => $appointment->id->toRfc4122(),
            'specialist' => [
                'id' => $appointment->specialistId->toRfc4122(),
                'name' => $appointment->specialistName,
                'specialization' => $appointment->specialistSpecialization,
            ],
            'client' => [
                'id' => $appointment->clientId->toRfc4122(),
                'name' => $appointment->clientName,
            ],
            'service' => [
                'id' => $appointment->serviceId->toRfc4122(),
                'name' => $appointment->serviceName,
                'defaultDurationMinutes' => $appointment->serviceDefaultDuration,
                'minimumDurationMinutes' => $appointment->serviceMinimumDuration,
                'maximumDurationMinutes' => $appointment->serviceMaximumDuration,
            ],
            'durationMinutes' => $appointment->durationMinutes,
            'date' => $startsAt->format('Y-m-d'),
            'startTime' => $startsAt->format('H:i'),
            'endTime' => $endsAt->format('H:i'),
            'startsAt' => $startsAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'endsAt' => $endsAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
