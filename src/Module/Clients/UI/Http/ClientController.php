<?php

declare(strict_types=1);

namespace App\Module\Clients\UI\Http;

use App\Module\Clients\Application\ClientDirectory;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Application\OrganizationPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/clients')]
final readonly class ClientController
{
    public function __construct(
        private ClientDirectory $directory,
        private AuthorizationCheckerInterface $authorization,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $search = $request->query->get('search');
        if (null !== $search && !is_string($search)) {
            return new JsonResponse(['message' => 'Некорректный поисковый запрос.'], 422);
        }

        return new JsonResponse($this->directory->list($search));
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->directory->details($this->owned($id, OrganizationPermission::VIEW)));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        return $this->respond(function () use ($request, $actor): array {
            $data = $this->body($request, ['name', 'type', 'phone', 'note', 'contactPerson', 'primaryChannel']);
            $contact = $this->objectOrNull($data, 'contactPerson', ['name', 'phone']);
            $channel = $this->objectOrNull($data, 'primaryChannel', ['recipient', 'provider', 'address']);
            $client = $this->directory->create(
                $actor,
                $this->string($data, 'name'),
                $this->string($data, 'type'),
                $this->nullableString($data, 'phone'),
                $this->nullableString($data, 'note'),
                $contact,
                $channel,
            );

            return $this->directory->details($client);
        }, 201);
    }

    #[Route('/{id}', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        return $this->respond(function () use ($id, $request): array {
            $client = $this->owned($id);
            $data = $this->body($request, ['name', 'type', 'phone', 'note']);
            $this->directory->update(
                $client,
                $this->string($data, 'name'),
                $this->string($data, 'type'),
                $this->nullableString($data, 'phone'),
                $this->nullableString($data, 'note'),
            );

            return $this->directory->details($client);
        });
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        return $this->respond(function () use ($id, $request): null {
            $this->checkCsrf($request);
            $this->directory->delete($this->owned($id));

            return null;
        }, 204);
    }

    #[Route('/{id}/contacts', methods: ['POST'])]
    #[Route('/{id}/contacts/{contactId}', methods: ['PUT'])]
    public function contact(string $id, Request $request, ?string $contactId = null): JsonResponse
    {
        return $this->respond(function () use ($id, $request, $contactId): array {
            $client = $this->owned($id);
            $data = $this->body($request, ['name', 'phone']);
            if (null === $contactId) {
                $this->directory->addContact($client, $this->string($data, 'name'), $this->nullableString($data, 'phone'));
            } else {
                $this->directory->updateContact($client, $contactId, $this->string($data, 'name'), $this->nullableString($data, 'phone'));
            }

            return $this->directory->details($client);
        }, null === $contactId ? 201 : 200);
    }

    #[Route('/{id}/contacts/{contactId}', methods: ['DELETE'])]
    public function deleteContact(string $id, string $contactId, Request $request): JsonResponse
    {
        return $this->respond(function () use ($id, $contactId, $request): array {
            $this->checkCsrf($request);
            $client = $this->owned($id);
            $this->directory->removeContact($client, $contactId);

            return $this->directory->details($client);
        });
    }

    #[Route('/{id}/channels', methods: ['POST'])]
    #[Route('/{id}/channels/{channelId}', methods: ['PUT'])]
    public function channel(string $id, Request $request, ?string $channelId = null): JsonResponse
    {
        return $this->respond(function () use ($id, $request, $channelId): array {
            $client = $this->owned($id);
            $data = $this->body($request, null === $channelId ? ['contactPersonId', 'provider', 'address', 'primary'] : ['provider', 'address']);
            if (null === $channelId) {
                $primary = $data['primary'] ?? false;
                if (!is_bool($primary)) {
                    throw new \InvalidArgumentException('Некорректный признак основного канала.');
                }
                $this->directory->addChannel(
                    $client,
                    $this->nullableString($data, 'contactPersonId'),
                    $this->string($data, 'provider'),
                    $this->string($data, 'address'),
                    $primary,
                );
            } else {
                $this->directory->updateChannel($client, $channelId, $this->string($data, 'provider'), $this->string($data, 'address'));
            }

            return $this->directory->details($client);
        }, null === $channelId ? 201 : 200);
    }

    #[Route('/{id}/channels/{channelId}', methods: ['DELETE'])]
    public function deleteChannel(string $id, string $channelId, Request $request): JsonResponse
    {
        return $this->respond(function () use ($id, $channelId, $request): array {
            $this->checkCsrf($request);
            $client = $this->owned($id);
            $this->directory->removeChannel($client, $channelId);

            return $this->directory->details($client);
        });
    }

    #[Route('/{id}/primary-channel', methods: ['PUT'])]
    public function primaryChannel(string $id, Request $request): JsonResponse
    {
        return $this->respond(function () use ($id, $request): array {
            $client = $this->owned($id);
            $data = $this->body($request, ['connectionId']);
            $this->directory->selectPrimaryChannel($client, $this->nullableString($data, 'connectionId'));

            return $this->directory->details($client);
        });
    }

    #[Route('/{id}/channels/{channelId}/webhook-secret', methods: ['PUT'])]
    public function webhookSecret(string $id, string $channelId, Request $request): JsonResponse
    {
        return $this->respond(function () use ($id, $channelId, $request): array {
            $client = $this->owned($id);
            $data = $this->body($request, ['secret']);
            $this->directory->configureChannelWebhookSecret($client, $channelId, $this->string($data, 'secret'));

            return $this->directory->details($client);
        });
    }

    #[Route('/{id}/channels/{channelId}/activation', methods: ['POST'])]
    public function activateChannel(string $id, string $channelId, Request $request): JsonResponse
    {
        return $this->respond(function () use ($id, $channelId, $request): array {
            $this->checkCsrf($request);
            $client = $this->owned($id);

            return $this->directory->startChannelActivation($client, $channelId);
        });
    }

    #[Route('/{id}/channels/{channelId}/deactivate', methods: ['POST'])]
    public function deactivateChannel(string $id, string $channelId, Request $request): JsonResponse
    {
        return $this->respond(function () use ($id, $channelId, $request): array {
            $this->checkCsrf($request);
            $client = $this->owned($id);
            $this->directory->deactivateChannel($client, $channelId);

            return $this->directory->details($client);
        });
    }

    private function owned(string $id, string $permission = OrganizationPermission::EDIT): Client
    {
        $client = $this->directory->find($id);
        if (!$this->authorization->isGranted($permission, $client)) {
            throw new \OutOfBoundsException('Клиент не найден.');
        }

        return $client;
    }

    /** @return array<string, mixed> */
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
     *  @param list<string> $allowed
     *  @return array<string, mixed>|null
     */
    private function objectOrNull(array $data, string $key, array $allowed): ?array
    {
        if (null === ($data[$key] ?? null)) {
            return null;
        }
        $value = $data[$key];
        if (!is_array($value) || array_is_list($value) || array_diff(array_keys($value), $allowed)) {
            throw new \InvalidArgumentException('Некорректные вложенные поля запроса.');
        }

        return $value;
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
