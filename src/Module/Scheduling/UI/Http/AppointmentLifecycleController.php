<?php

declare(strict_types=1);

namespace App\Module\Scheduling\UI\Http;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Application\AppointmentHistoryRecorder;
use App\Module\Scheduling\Application\AppointmentLifecycleService;
use App\Module\Scheduling\Domain\Model\AppointmentResultStatus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Ulid;

final readonly class AppointmentLifecycleController
{
    public function __construct(
        private AppointmentLifecycleService $lifecycle,
        private AppointmentHistoryRecorder $history,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/api/appointments/{id}/result', methods: ['GET'])]
    public function currentResult(string $id): JsonResponse
    {
        try {
            return new JsonResponse($this->lifecycle->result(Ulid::fromString($id)));
        } catch (\InvalidArgumentException|\OutOfBoundsException) {
            return new JsonResponse(['message' => 'Занятие не найдено.'], 404);
        }
    }

    #[Route('/api/appointments/{id}/result', methods: ['PUT'])]
    public function result(string $id, Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        try {
            $this->checkCsrf($request);
            $data = $this->body($request);
            $status = AppointmentResultStatus::from($this->string($data, 'status'));
            if (AppointmentResultStatus::Rescheduled === $status) {
                throw new \InvalidArgumentException('Перенос оформляется через отдельный процесс.');
            }
            $result = $this->lifecycle->recordResult(
                $actor,
                Ulid::fromString($id),
                $status,
                $this->boolean($data, 'respectfulReason'),
                $this->nullableString($data, 'comment'),
                $this->boolean($data, 'createFreeWindow'),
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
            $appointment = $result['appointment'];

            return new JsonResponse([
                'status' => $appointment->resultStatus()?->value,
                'recordedAt' => $appointment->resultRecordedAt()?->format(\DateTimeInterface::RFC3339_EXTENDED),
                'lateCancellation' => $appointment->resultIsLate(),
                'respectfulReason' => $appointment->resultRespectfulReason(),
                'comment' => $appointment->resultComment(),
                'freeWindowCreated' => $result['freeWindowCreated'],
            ]);
        } catch (\ValueError|\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage() ?: 'Некорректный результат занятия.'], 422);
        } catch (\OutOfBoundsException) {
            return new JsonResponse(['message' => 'Занятие не найдено.'], 404);
        } catch (\DomainException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 409);
        } catch (\UnexpectedValueException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        }
    }

    #[Route('/api/appointments/{id}/history', methods: ['GET'])]
    public function history(string $id): JsonResponse
    {
        try {
            return new JsonResponse($this->history->history(Ulid::fromString($id)));
        } catch (\InvalidArgumentException|\OutOfBoundsException) {
            return new JsonResponse(['message' => 'Занятие не найдено.'], 404);
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
        $allowed = ['status', 'respectfulReason', 'comment', 'createFreeWindow'];
        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), $allowed) || array_diff($allowed, array_keys($data))) {
            throw new \InvalidArgumentException('Некорректные поля результата занятия.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        if (!is_string($data[$key] ?? null)) {
            throw new \InvalidArgumentException('Выберите результат занятия.');
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function boolean(array $data, string $key): bool
    {
        if (!is_bool($data[$key] ?? null)) {
            throw new \InvalidArgumentException('Некорректные параметры результата.');
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function nullableString(array $data, string $key): ?string
    {
        if (null !== ($data[$key] ?? null) && !is_string($data[$key])) {
            throw new \InvalidArgumentException('Некорректный комментарий.');
        }
        return $data[$key] ?? null;
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('mutation', $request->headers->get('X-CSRF-Token')))) {
            throw new \UnexpectedValueException('Сессия формы устарела. Обновите страницу.');
        }
    }
}
