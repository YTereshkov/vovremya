<?php

declare(strict_types=1);

namespace App\Module\Communications\UI\Http;

use App\Module\Communications\Application\MessageTemplateCatalog;
use App\Module\Communications\Domain\Model\MessageTemplateType;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/communications/templates')]
final readonly class MessageTemplateController
{
    public function __construct(private MessageTemplateCatalog $templates, private CsrfTokenManagerInterface $csrf)
    {
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse($this->templates->list());
    }

    #[Route('/{type}', methods: ['PUT'])]
    public function save(string $type, Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        return $this->respond(function () use ($type, $request, $actor): array {
            $data = $this->body($request);
            if (!is_string($data['body'] ?? null)) {
                throw new \InvalidArgumentException('Укажите текст шаблона.');
            }

            return $this->templates->save($actor, MessageTemplateType::from(strtoupper($type)), $data['body']);
        });
    }

    #[Route('/{type}', methods: ['DELETE'])]
    public function restore(string $type, Request $request): JsonResponse
    {
        return $this->respond(function () use ($type, $request): array {
            $this->checkCsrf($request);

            return $this->templates->restore(MessageTemplateType::from(strtoupper($type)));
        });
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $this->checkCsrf($request);
        try { $data = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \InvalidArgumentException('Некорректный JSON.'); }
        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), ['body'])) {
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

    private function respond(callable $operation): JsonResponse
    {
        try { return new JsonResponse($operation()); }
        catch (\ValueError|\InvalidArgumentException $e) { return new JsonResponse(['message' => $e->getMessage() ?: 'Неизвестный тип шаблона.'], 422); }
        catch (\UnexpectedValueException $e) { return new JsonResponse(['message' => $e->getMessage()], 403); }
    }
}
