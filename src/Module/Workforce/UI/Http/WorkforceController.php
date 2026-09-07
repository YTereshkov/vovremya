<?php

declare(strict_types=1);

namespace App\Module\Workforce\UI\Http;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Application\OrganizationPermission;
use App\Module\Workforce\Application\WorkforceService;
use App\Module\Workforce\Domain\Model\Specialist;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class WorkforceController
{
    public function __construct(private WorkforceService $service, private AuthorizationCheckerInterface $authorization, private CsrfTokenManagerInterface $csrf) {}

    #[Route('/api/specialists', methods: ['GET'])]
    public function list(): JsonResponse { return new JsonResponse($this->service->list()); }

    #[Route('/api/specialists/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->service->details($this->owned($id, OrganizationPermission::VIEW)));
    }

    #[Route('/api/specialists', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        return $this->respond(function () use ($request, $actor): array {
            $data = $this->body($request, ['name', 'specialization', 'administratorId']);
            $s = $this->service->create($actor, $this->string($data, 'name'), $this->string($data, 'specialization'), $this->administrator($data));

            return $this->service->details($s);
        }, 201);
    }

    #[Route('/api/specialists/{id}', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        return $this->respond(function () use ($id, $request): array {
            $s = $this->owned($id);
            $data = $this->body($request, ['name', 'specialization', 'administratorId']);
            $this->service->update($s, $this->string($data, 'name'), $this->string($data, 'specialization'), $this->administrator($data));

            return $this->service->details($s);
        });
    }

    #[Route('/api/specialists/{id}', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        return $this->respond(function () use ($id, $request): null {
            $this->checkCsrf($request);
            $this->service->delete($this->owned($id));

            return null;
        }, 204);
    }

    #[Route('/api/specialists/{id}/weekly-hours', methods: ['PUT'])]
    public function week(string $id, Request $request): JsonResponse
    {
        return $this->respond(function () use ($id, $request): array {
            $s = $this->owned($id);
            $data = $this->body($request, ['days']);
            if (!is_array($data['days'] ?? null)) { throw new \InvalidArgumentException('Укажите дни недели.'); }
            $this->service->saveWeek($s, $data['days']);

            return $this->service->details($s);
        });
    }

    #[Route('/api/specialists/{id}/additional-days', methods: ['POST'])]
    #[Route('/api/specialists/{id}/additional-days/{dayId}', methods: ['PUT'])]
    public function day(string $id, Request $request, ?string $dayId = null): JsonResponse
    {
        return $this->respond(function () use ($id, $request, $dayId): array {
            $s = $this->owned($id);
            $data = $this->body($request, ['date', 'work']);
            if (!is_array($data['work'] ?? null)) { throw new \InvalidArgumentException('Укажите рабочее время.'); }
            $this->service->saveAdditionalDay($s, $dayId, $this->string($data, 'date'), $data['work']);

            return $this->service->details($s);
        }, null === $dayId ? 201 : 200);
    }

    #[Route('/api/specialists/{id}/additional-days/{dayId}', methods: ['DELETE'])]
    public function deleteDay(string $id, string $dayId, Request $request): JsonResponse
    {
        return $this->respond(function () use ($id, $dayId, $request): null {
            $this->checkCsrf($request);
            $this->service->deleteAdditionalDay($this->owned($id), $dayId);

            return null;
        }, 204);
    }

    private function owned(string $id, string $permission = OrganizationPermission::EDIT): Specialist
    {
        $specialist = $this->service->find($id);
        if (!$this->authorization->isGranted($permission, $specialist)) { throw new \OutOfBoundsException('Специалист не найден.'); }

        return $specialist;
    }

    private function body(Request $request, array $allowed): array
    {
        $this->checkCsrf($request);
        try { $data = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \InvalidArgumentException('Некорректный JSON.'); }
        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), $allowed)) {
            throw new \InvalidArgumentException('Некорректные поля запроса.');
        }

        return $data;
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('mutation', $request->headers->get('X-CSRF-Token')))) {
            throw new \UnexpectedValueException('Сессия формы устарела. Обновите страницу.');
        }
    }

    private function string(array $data, string $key): string
    {
        if (!is_string($data[$key] ?? null)) { throw new \InvalidArgumentException('Заполните обязательные поля.'); }

        return $data[$key];
    }

    private function administrator(array $data): ?string
    {
        if (null !== ($data['administratorId'] ?? null) && !is_string($data['administratorId'])) {
            throw new \InvalidArgumentException('Некорректный администратор.');
        }

        return $data['administratorId'] ?? null;
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
