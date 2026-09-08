<?php

declare(strict_types=1);

namespace App\Module\Scheduling\UI\Http;

use App\Module\Clients\Application\ClientStore;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Application\RegularScheduleCreator;
use App\Module\Scheduling\Application\RegularScheduleManager;
use App\Module\Scheduling\Application\RegularScheduleStore;
use App\Module\Scheduling\Domain\Model\RegularSchedule;
use App\Module\Scheduling\Domain\Model\RegularScheduleDay;
use App\Module\Scheduling\Domain\Model\ScheduleGenerationIssue;
use App\Module\Scheduling\Domain\RegularScheduleConflicts;
use App\Module\Workforce\Application\WorkforceStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Ulid;

final readonly class RegularScheduleController
{
    public function __construct(
        private RegularScheduleCreator $creator,
        private RegularScheduleManager $manager,
        private RegularScheduleStore $schedules,
        private WorkforceStore $workforce,
        private ClientStore $clients,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/api/regular-schedules', methods: ['GET'])]
    public function all(#[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        return new JsonResponse(array_map(fn (RegularSchedule $schedule): array => $this->present($schedule, $actor), $this->schedules->all()));
    }

    #[Route('/api/regular-schedules/{id}', methods: ['GET'])]
    public function one(string $id, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        $schedule = $this->find($id);

        return null === $schedule
            ? new JsonResponse(['message' => 'Регулярное расписание не найдено.'], 404)
            : new JsonResponse($this->present($schedule, $actor));
    }

    #[Route('/api/regular-schedules', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        try {
            $this->checkCsrf($request);
            $data = $this->body($request, ['specialistId', 'clientId', 'serviceId', 'startsOn', 'endsOn', 'days']);
            $schedule = $this->creator->create(
                $actor,
                $this->requiredString($data, 'specialistId'),
                $this->requiredString($data, 'clientId'),
                $this->requiredString($data, 'serviceId'),
                $this->requiredString($data, 'startsOn'),
                $this->optionalString($data, 'endsOn'),
                $this->days($data),
            );

            return new JsonResponse($this->present($schedule, $actor), 201);
        } catch (RegularScheduleConflicts $exception) {
            return new JsonResponse([
                'kind' => 'HARD_CONFLICT',
                'message' => $exception->getMessage(),
                'conflicts' => array_map(static fn ($conflict): array => $conflict->toArray(), $exception->conflicts),
            ], 409);
        } catch (\OutOfBoundsException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 404);
        } catch (\UnexpectedValueException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 422);
        }
    }

    #[Route('/api/regular-schedules/{id}/end', methods: ['POST'])]
    public function end(string $id, Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        return $this->change($request, $actor, function (array $data) use ($id, $actor): array {
            $schedule = $this->manager->end($id, $this->date($this->requiredString($data, 'fromDate'), $actor));

            return $this->present($schedule, $actor);
        }, ['fromDate']);
    }

    #[Route('/api/regular-schedules/{id}/days/{dayId}/end', methods: ['POST'])]
    public function endDay(string $id, string $dayId, Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        return $this->change($request, $actor, function (array $data) use ($id, $dayId, $actor): array {
            $this->manager->endDay($id, $dayId, $this->date($this->requiredString($data, 'fromDate'), $actor));
            $schedule = $this->find($id) ?? throw new \OutOfBoundsException('Регулярное расписание не найдено.');

            return $this->present($schedule, $actor);
        }, ['fromDate']);
    }

    #[Route('/api/regular-schedules/{id}/days/{dayId}', methods: ['PATCH'])]
    public function replaceDay(string $id, string $dayId, Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        return $this->change($request, $actor, function (array $data) use ($id, $dayId, $actor): array {
            $this->manager->replaceDay(
                $id,
                $dayId,
                $this->date($this->requiredString($data, 'fromDate'), $actor),
                $this->requiredString($data, 'startTime'),
                $this->requiredInteger($data, 'durationMinutes'),
            );
            $schedule = $this->find($id) ?? throw new \OutOfBoundsException('Регулярное расписание не найдено.');

            return $this->present($schedule, $actor);
        }, ['fromDate', 'startTime', 'durationMinutes']);
    }

    #[Route('/api/schedule-generation-issues/{id}/retry', methods: ['POST'])]
    public function retryIssue(string $id, Request $request): JsonResponse
    {
        try {
            $this->checkCsrf($request);
            $this->manager->retryIssue($id);
            $issue = $this->schedules->findIssue(Ulid::fromString($id));

            return new JsonResponse(null === $issue ? null : $this->presentIssue($issue));
        } catch (\OutOfBoundsException|\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 404);
        } catch (\UnexpectedValueException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        }
    }

    /** @param list<string> $allowed */
    private function change(Request $request, AdministratorAccount $actor, callable $operation, array $allowed): JsonResponse
    {
        try {
            $this->checkCsrf($request);

            return new JsonResponse($operation($this->body($request, $allowed)));
        } catch (\OutOfBoundsException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 404);
        } catch (\UnexpectedValueException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 422);
        }
    }

    /** @return array<string, mixed> */
    private function present(RegularSchedule $schedule, AdministratorAccount $actor): array
    {
        $specialist = $this->workforce->find($schedule->specialistId()) ?? throw new \LogicException('Regular schedule specialist is missing.');
        $client = $this->clients->find($schedule->clientId()) ?? throw new \LogicException('Regular schedule client is missing.');
        $today = new \DateTimeImmutable('today', new \DateTimeZone($actor->organization()->timezone()));
        $referenceDate = $today < $schedule->startsOn() ? $schedule->startsOn() : $today;
        $days = array_values(array_filter(
            $this->schedules->days($schedule->id(), true),
            static fn (RegularScheduleDay $day): bool => $day->isActiveOn($referenceDate),
        ));

        return [
            'id' => $schedule->id()->toRfc4122(),
            'specialist' => ['id' => $specialist->id()->toRfc4122(), 'name' => $specialist->name(), 'specialization' => $specialist->specialization()],
            'client' => ['id' => $client->id()->toRfc4122(), 'name' => $client->name()],
            'service' => [
                'id' => $schedule->serviceId()->toRfc4122(),
                'name' => $schedule->serviceName(),
                'defaultDurationMinutes' => $schedule->serviceDefaultDuration(),
                'minimumDurationMinutes' => $schedule->serviceMinimumDuration(),
                'maximumDurationMinutes' => $schedule->serviceMaximumDuration(),
            ],
            'startsOn' => $schedule->startsOn()->format('Y-m-d'),
            'endsOn' => $schedule->endsOn()?->format('Y-m-d'),
            'active' => $schedule->isActiveOn($referenceDate),
            'days' => array_map(static fn (RegularScheduleDay $day): array => [
                'id' => $day->id()->toRfc4122(),
                'weekday' => $day->weekday(),
                'startTime' => $day->startTime(),
                'durationMinutes' => $day->durationMinutes(),
            ], $days),
            'issues' => array_map(fn (ScheduleGenerationIssue $issue): array => $this->presentIssue($issue), $this->schedules->openIssues($schedule->id())),
        ];
    }

    /** @return array<string, mixed> */
    private function presentIssue(ScheduleGenerationIssue $issue): array
    {
        return [
            'id' => $issue->id()->toRfc4122(),
            'scheduleId' => $issue->regularScheduleId()->toRfc4122(),
            'dayId' => $issue->regularScheduleDayId()->toRfc4122(),
            'date' => $issue->occurrenceDate()->format('Y-m-d'),
            'code' => $issue->conflictCode(),
            'message' => $issue->conflictMessage(),
            'status' => $issue->status(),
        ];
    }

    private function find(string $id): ?RegularSchedule
    {
        try {
            return $this->schedules->find(Ulid::fromString($id));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @param list<string> $allowed
     *  @return array<string, mixed>
     */
    private function body(Request $request, array $allowed): array
    {
        try {
            $data = json_decode($request->getContent() ?: '{}', true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Некорректный JSON.');
        }
        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), $allowed)) {
            throw new \InvalidArgumentException('Некорректные поля запроса.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key): string
    {
        if (!is_string($data[$key] ?? null) || '' === trim($data[$key])) {
            throw new \InvalidArgumentException('Заполните обязательные поля.');
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function optionalString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || null === $data[$key] || '' === $data[$key]) {
            return null;
        }
        if (!is_string($data[$key])) {
            throw new \InvalidArgumentException('Некорректное поле.');
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function requiredInteger(array $data, string $key): int
    {
        if (!is_int($data[$key] ?? null)) {
            throw new \InvalidArgumentException('Длительность должна быть целым числом минут.');
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data
     *  @return list<array{weekday: int, startTime: string, durationMinutes?: int|null}>
     */
    private function days(array $data): array
    {
        if (!is_array($data['days'] ?? null) || !array_is_list($data['days'])) {
            throw new \InvalidArgumentException('Некорректные дни регулярного расписания.');
        }
        foreach ($data['days'] as $day) {
            if (!is_array($day) || array_is_list($day) || array_diff(array_keys($day), ['weekday', 'startTime', 'durationMinutes'])) {
                throw new \InvalidArgumentException('Некорректные дни регулярного расписания.');
            }
        }

        return $data['days'];
    }

    private function date(string $value, AdministratorAccount $actor): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone($actor->organization()->timezone());
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Укажите корректную дату.');
        }
        if ($date < new \DateTimeImmutable('today', $timezone)) {
            throw new \InvalidArgumentException('Дата изменения не может быть в прошлом.');
        }

        return $date;
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('mutation', $request->headers->get('X-CSRF-Token')))) {
            throw new \UnexpectedValueException('Сессия формы устарела. Обновите страницу.');
        }
    }
}
