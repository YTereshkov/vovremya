<?php

declare(strict_types=1);

namespace App\Module\Scheduling\UI\Http;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Application\TransferService;
use App\Module\Scheduling\Domain\Model\TransferOption;
use App\Module\Scheduling\Domain\SoftWarningsRequired;
use App\Module\Scheduling\Domain\TimeUnavailable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Ulid;

final readonly class TransferController
{
    public function __construct(private TransferService $transfers, private CsrfTokenManagerInterface $csrf)
    {
    }

    #[Route('/api/appointments/{id}/transfer', methods: ['GET'])]
    public function state(string $id): JsonResponse
    {
        try {
            return new JsonResponse($this->transfers->state(Ulid::fromString($id)));
        } catch (\InvalidArgumentException|\OutOfBoundsException) {
            return new JsonResponse(['message' => 'Занятие не найдено.'], 404);
        }
    }

    #[Route('/api/appointments/{id}/transfer', methods: ['POST'])]
    public function offer(string $id, Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        try {
            $this->checkCsrf($request);
            $data = $this->body($request);
            $options = $data['options'] ?? null;
            if (!is_array($options) || !array_is_list($options)) {
                throw new \InvalidArgumentException('Некорректные варианты переноса.');
            }
            $startsAt = array_map(fn (mixed $option): \DateTimeImmutable => $this->option($option, $actor->organization()->timezone()), $options);
            $result = $this->transfers->offer(
                $actor,
                Ulid::fromString($id),
                $startsAt,
                $this->warningCodes($data),
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );

            return new JsonResponse([
                'request' => [
                    'id' => $result['request']->id()->toRfc4122(),
                    'status' => $result['request']->status()->value,
                    'newAppointmentId' => null,
                    'createdAt' => $result['request']->createdAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
                ],
                'options' => array_map(static fn (TransferOption $option): array => [
                    'id' => $option->id()->toRfc4122(),
                    'startsAt' => $option->startsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
                    'endsAt' => $option->endsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
                    'selectedAt' => null,
                ], $result['options']),
            ], 201);
        } catch (SoftWarningsRequired $exception) {
            return new JsonResponse([
                'kind' => 'SOFT_WARNING',
                'message' => $exception->getMessage(),
                'warnings' => array_map(static fn ($warning): array => $warning->toArray(), $exception->warnings),
            ], 409);
        } catch (TimeUnavailable $exception) {
            return new JsonResponse(['kind' => 'HARD_CONFLICT', 'message' => $exception->getMessage(), 'conflict' => $exception->conflict->toArray()], 409);
        } catch (\OutOfBoundsException) {
            return new JsonResponse(['message' => 'Занятие не найдено.'], 404);
        } catch (\UnexpectedValueException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 422);
        } catch (\DomainException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 409);
        }
    }

    #[Route('/api/transfer-requests/{id}', methods: ['DELETE'])]
    public function cancel(string $id, Request $request, #[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        try {
            $this->checkCsrf($request);
            $this->transfers->cancel($actor, Ulid::fromString($id), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

            return new JsonResponse(null, 204);
        } catch (\InvalidArgumentException|\OutOfBoundsException) {
            return new JsonResponse(['message' => 'Запрос переноса не найден.'], 404);
        } catch (\UnexpectedValueException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        } catch (\DomainException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 409);
        }
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        try {
            $data = json_decode($request->getContent(), true, 24, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Некорректный JSON.');
        }
        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), ['options', 'acceptedWarnings'])) {
            throw new \InvalidArgumentException('Некорректные поля запроса.');
        }

        return $data;
    }

    private function option(mixed $option, string $timezone): \DateTimeImmutable
    {
        if (!is_array($option) || array_is_list($option) || array_diff(array_keys($option), ['date', 'startTime'])
            || !is_string($option['date'] ?? null) || !is_string($option['startTime'] ?? null)) {
            throw new \InvalidArgumentException('Некорректный вариант переноса.');
        }
        $value = $option['date'].' '.$option['startTime'];
        $instant = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, new \DateTimeZone($timezone));
        if (false === $instant || $instant->format('Y-m-d H:i') !== $value) {
            throw new \InvalidArgumentException('Укажите корректные дату и время переноса.');
        }

        return $instant;
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

    private function checkCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('mutation', $request->headers->get('X-CSRF-Token')))) {
            throw new \UnexpectedValueException('Сессия формы устарела. Обновите страницу.');
        }
    }
}
