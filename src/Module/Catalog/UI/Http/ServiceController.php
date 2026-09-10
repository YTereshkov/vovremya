<?php

declare(strict_types=1);

namespace App\Module\Catalog\UI\Http;

use App\Module\Catalog\Application\ServiceCatalog;
use App\Module\Catalog\Domain\Model\Service;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Application\OrganizationPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/services')]
final readonly class ServiceController
{
    public function __construct(
        private ServiceCatalog $catalog,
        private AuthorizationCheckerInterface $authorization,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse { return new JsonResponse($this->catalog->list()); }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->catalog->present($this->owned($id, OrganizationPermission::VIEW)));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        return $this->respond(function () use ($request, $actor): array {
            $data = $this->body($request);
            $service = $this->catalog->create($actor, $this->string($data, 'name'), ...[...$this->durations($data), $this->nullableString($data, 'confirmationTemplate')]);

            return $this->catalog->present($service);
        }, 201);
    }

    #[Route('/{id}', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        return $this->respond(function () use ($id, $request): array {
            $service = $this->owned($id);
            $data = $this->body($request);
            $this->catalog->update($service, $this->string($data, 'name'), ...[...$this->durations($data), $this->nullableString($data, 'confirmationTemplate')]);

            return $this->catalog->present($service);
        });
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        return $this->respond(function () use ($id, $request): null {
            $this->checkCsrf($request);
            $this->catalog->delete($this->owned($id));

            return null;
        }, 204);
    }

    private function owned(string $id, string $permission = OrganizationPermission::EDIT): Service
    {
        $service = $this->catalog->find($id);
        if (!$this->authorization->isGranted($permission, $service)) {
            throw new \OutOfBoundsException('Услуга не найдена.');
        }

        return $service;
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $this->checkCsrf($request);
        try {
            $data = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Некорректный JSON.');
        }
        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), ['name', 'defaultDurationMinutes', 'minimumDurationMinutes', 'maximumDurationMinutes', 'confirmationTemplate'])) {
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

    /** @param array<string, mixed> $data
     *  @return array{int, int|null, int|null}
     */
    private function durations(array $data): array
    {
        if (!is_int($data['defaultDurationMinutes'] ?? null)) {
            throw new \InvalidArgumentException('Длительность по умолчанию должна быть целым числом минут.');
        }
        foreach (['minimumDurationMinutes', 'maximumDurationMinutes'] as $key) {
            if (null !== ($data[$key] ?? null) && !is_int($data[$key])) {
                throw new \InvalidArgumentException('Границы длительности должны быть целым числом минут или пустыми.');
            }
        }

        return [$data['defaultDurationMinutes'], $data['minimumDurationMinutes'] ?? null, $data['maximumDurationMinutes'] ?? null];
    }

    private function respond(callable $operation, int $status = 200): JsonResponse
    {
        try { return new JsonResponse($operation(), $status); }
        catch (\OutOfBoundsException $e) { return new JsonResponse(['message' => $e->getMessage()], 404); }
        catch (\UnexpectedValueException $e) { return new JsonResponse(['message' => $e->getMessage()], 403); }
        catch (\InvalidArgumentException $e) { return new JsonResponse(['message' => $e->getMessage()], 422); }
    }
}
