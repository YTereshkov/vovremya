<?php

declare(strict_types=1);

namespace App\Module\Clients\UI\Http;

use App\Module\Clients\Application\ClientAbsenceService;
use App\Module\Clients\Application\ClientDirectory;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Clients\Domain\Model\ClientAbsenceMode;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Application\OrganizationPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class ClientAbsenceController
{
    public function __construct(
        private ClientDirectory $clients,
        private ClientAbsenceService $absences,
        private AuthorizationCheckerInterface $authorization,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/api/clients/{id}/absence-impact', methods: ['GET'])]
    public function impact(string $id, Request $request): JsonResponse
    {
        return $this->respond(function () use ($id, $request): array {
            $client = $this->owned($id, OrganizationPermission::VIEW);
            $startsOn = $this->date($request->query->get('startsOn'));
            $endsOn = $this->date($request->query->get('endsOn'));

            return ['appointments' => $this->absences->impact($client, $startsOn, $endsOn)];
        });
    }

    #[Route('/api/clients/{id}/absences', methods: ['POST'])]
    public function create(string $id, Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        return $this->respond(function () use ($id, $request, $actor): array {
            $client = $this->owned($id);
            $data = $this->body($request, ['startsOn', 'endsOn', 'reason', 'mode', 'createFreeWindows', 'notifyClient']);
            $mode = ClientAbsenceMode::tryFrom($this->string($data, 'mode'))
                ?? throw new \InvalidArgumentException('Выберите действие с постоянным местом.');
            $createWindows = $data['createFreeWindows'] ?? false;
            $notify = $data['notifyClient'] ?? true;
            if (!is_bool($createWindows) || !is_bool($notify)) {
                throw new \InvalidArgumentException('Некорректные параметры отсутствия.');
            }
            $result = $this->absences->create(
                $actor,
                $client,
                $this->date($data['startsOn'] ?? null),
                $this->date($data['endsOn'] ?? null),
                $this->nullableString($data, 'reason'),
                $mode,
                $createWindows,
                $notify,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
            $absence = $result['absence'];

            return [
                'id' => $absence->id()->toRfc4122(),
                'startsOn' => $absence->startsOn()->format('Y-m-d'),
                'endsOn' => $absence->endsOn()->format('Y-m-d'),
                'reason' => $absence->reason(),
                'mode' => $absence->mode()->value,
                'createFreeWindows' => $absence->createFreeWindows(),
                'notifyClient' => $absence->notifyClient(),
                'affectedAppointments' => $result['appointments'],
                'freeWindowsCreated' => $result['freeWindows'],
                'queuedNotifications' => $result['notifications'],
                'regularSchedulesEnded' => $result['regularSchedulesEnded'],
            ];
        }, 201);
    }

    private function owned(string $id, string $permission = OrganizationPermission::EDIT): Client
    {
        $client = $this->clients->find($id);
        if (!$this->authorization->isGranted($permission, $client)) {
            throw new \OutOfBoundsException('Клиент не найден.');
        }

        return $client;
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
