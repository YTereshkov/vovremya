<?php

declare(strict_types=1);

namespace App\Module\Scheduling\UI\Http;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Application\AppointmentCreator;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\SoftWarningsRequired;
use App\Module\Scheduling\Domain\TimeUnavailable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class AppointmentController
{
    public function __construct(
        private AppointmentCreator $creator,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/api/appointments', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        try {
            $this->checkCsrf($request);
            $data = $this->body($request);
            $appointment = $this->creator->create(
                $actor,
                $this->string($data, 'specialistId'),
                $this->string($data, 'clientId'),
                $this->string($data, 'serviceId'),
                $this->string($data, 'date'),
                $this->string($data, 'startTime'),
                $this->optionalInteger($data, 'durationMinutes'),
                $this->warningCodes($data),
            );

            return new JsonResponse($this->present($appointment), 201);
        } catch (SoftWarningsRequired $exception) {
            return new JsonResponse([
                'kind' => 'SOFT_WARNING',
                'message' => $exception->getMessage(),
                'warnings' => array_map(static fn ($warning): array => $warning->toArray(), $exception->warnings),
            ], 409);
        } catch (TimeUnavailable $exception) {
            return new JsonResponse([
                'kind' => 'HARD_CONFLICT',
                'message' => $exception->getMessage(),
                'conflict' => $exception->conflict->toArray(),
            ], 409);
        } catch (\OutOfBoundsException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 404);
        } catch (\UnexpectedValueException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 422);
        }
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        try {
            $data = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Некорректный JSON.');
        }

        $allowed = ['specialistId', 'clientId', 'serviceId', 'date', 'startTime', 'durationMinutes', 'acceptedWarnings'];
        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), $allowed)) {
            throw new \InvalidArgumentException('Некорректные поля запроса.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        if (!is_string($data[$key] ?? null) || '' === trim($data[$key])) {
            throw new \InvalidArgumentException('Заполните обязательные поля.');
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function optionalInteger(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data) || null === $data[$key]) {
            return null;
        }
        if (!is_int($data[$key])) {
            throw new \InvalidArgumentException('Длительность занятия должна быть целым числом минут.');
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data
     *  @return list<string>
     */
    private function warningCodes(array $data): array
    {
        $warnings = $data['acceptedWarnings'] ?? [];
        if (!is_array($warnings) || !array_is_list($warnings)) {
            throw new \InvalidArgumentException('Некорректные подтверждения предупреждений.');
        }
        foreach ($warnings as $warning) {
            if (!is_string($warning) || !in_array($warning, ['LUNCH_OVERLAP', 'SHORT_BREAK'], true)) {
                throw new \InvalidArgumentException('Некорректные подтверждения предупреждений.');
            }
        }

        return array_values(array_unique($warnings));
    }

    /** @return array<string, mixed> */
    private function present(Appointment $appointment): array
    {
        return [
            'id' => $appointment->id()->toRfc4122(),
            'specialistId' => $appointment->specialistId()->toRfc4122(),
            'clientId' => $appointment->clientId()->toRfc4122(),
            'serviceId' => $appointment->serviceId()->toRfc4122(),
            'service' => [
                'name' => $appointment->serviceName(),
                'defaultDurationMinutes' => $appointment->serviceDefaultDuration(),
                'minimumDurationMinutes' => $appointment->serviceMinimumDuration(),
                'maximumDurationMinutes' => $appointment->serviceMaximumDuration(),
            ],
            'durationMinutes' => $appointment->durationMinutes(),
            'startsAt' => $appointment->startsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
            'endsAt' => $appointment->endsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('mutation', $request->headers->get('X-CSRF-Token')))) {
            throw new \UnexpectedValueException('Сессия формы устарела. Обновите страницу.');
        }
    }
}
