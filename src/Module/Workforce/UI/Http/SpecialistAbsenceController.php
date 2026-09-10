<?php

declare(strict_types=1);

namespace App\Module\Workforce\UI\Http;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Application\OrganizationPermission;
use App\Module\Workforce\Application\SpecialistAbsenceService;
use App\Module\Workforce\Application\WorkforceService;
use App\Module\Workforce\Domain\Model\Specialist;
use App\Module\Workforce\Domain\Model\SpecialistAbsenceType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class SpecialistAbsenceController
{
    public function __construct(
        private WorkforceService $workforce,
        private SpecialistAbsenceService $absences,
        private AuthorizationCheckerInterface $authorization,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/api/specialists/{id}/absence-impact', methods: ['GET'])]
    public function impact(string $id, Request $request): JsonResponse
    {
        return $this->respond(function () use ($id, $request): array {
            $specialist = $this->owned($id, OrganizationPermission::VIEW);
            $startsOn = $this->date($request->query->get('startsOn'));
            $endsOn = $this->date($request->query->get('endsOn'));

            return ['appointments' => $this->absences->impact($specialist, $startsOn, $endsOn)];
        });
    }

    #[Route('/api/specialists/{id}/absences', methods: ['POST'])]
    public function create(string $id, Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        return $this->respond(function () use ($id, $request, $actor): array {
            $specialist = $this->owned($id);
            $data = $this->body($request, ['type', 'startsOn', 'endsOn', 'comment', 'notifyClients']);
            $type = SpecialistAbsenceType::tryFrom($this->string($data, 'type'))
                ?? throw new \InvalidArgumentException('Выберите тип отсутствия.');
            $notify = $data['notifyClients'] ?? true;
            if (!is_bool($notify)) {
                throw new \InvalidArgumentException('Некорректный признак уведомления клиентов.');
            }
            $result = $this->absences->create(
                $actor,
                $specialist,
                $type,
                $this->date($data['startsOn'] ?? null),
                $this->date($data['endsOn'] ?? null),
                $this->nullableString($data, 'comment'),
                $notify,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
            $absence = $result['absence'];

            return [
                'id' => $absence->id()->toRfc4122(),
                'type' => $absence->type()->value,
                'startsOn' => $absence->startsOn()->format('Y-m-d'),
                'endsOn' => $absence->endsOn()->format('Y-m-d'),
                'comment' => $absence->comment(),
                'notifyClients' => $absence->notifyClients(),
                'affectedAppointments' => $result['appointments'],
                'queuedNotifications' => $result['notifications'],
            ];
        }, 201);
    }

    private function owned(string $id, string $permission = OrganizationPermission::EDIT): Specialist
    {
        $specialist = $this->workforce->find($id);
        if (!$this->authorization->isGranted($permission, $specialist)) {
            throw new \OutOfBoundsException('Специалист не найден.');
        }

        return $specialist;
    }

    /** @return array<string, mixed> */
    private function body(Request $request, array $allowed): array
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('mutation', $request->headers->get('X-CSRF-Token')))) {
            throw new \UnexpectedValueException('Сессия формы устарела. Обновите страницу.');
        }
        try { $data = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \InvalidArgumentException('Некорректный JSON.'); }
        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), $allowed)) {
            throw new \InvalidArgumentException('Некорректные поля запроса.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        if (!is_string($data[$key] ?? null)) {
            throw new \InvalidArgumentException('Заполните обязательные поля.');
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function nullableString(array $data, string $key): ?string
    {
        if (null !== ($data[$key] ?? null) && !is_string($data[$key])) {
            throw new \InvalidArgumentException('Некорректное текстовое поле.');
        }

        return $data[$key] ?? null;
    }

    private function date(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Укажите даты отсутствия.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Некорректная дата отсутствия.');
        }

        return $date;
    }

    private function respond(callable $operation, int $status = 200): JsonResponse
    {
        try { return new JsonResponse($operation(), $status); }
        catch (\OutOfBoundsException $e) { return new JsonResponse(['message' => $e->getMessage()], 404); }
        catch (\UnexpectedValueException $e) { return new JsonResponse(['message' => $e->getMessage()], 403); }
        catch (\InvalidArgumentException $e) { return new JsonResponse(['message' => $e->getMessage()], 422); }
        catch (\DomainException $e) { return new JsonResponse(['message' => $e->getMessage()], 409); }
    }
}
