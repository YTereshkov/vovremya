<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Communications\Application\ChannelProviderRegistry;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Reporting\Application\NotificationCenterQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Ulid;

final readonly class NotificationCenterController
{
    public function __construct(private NotificationCenterQuery $notifications, private ChannelProviderRegistry $providers)
    {
    }

    #[Route('/api/notifications', methods: ['GET'])]
    public function list(#[CurrentUser] AdministratorAccount $administrator): JsonResponse
    {
        return new JsonResponse($this->notifications->list($administrator->id()));
    }

    #[Route('/api/notifications/delivery/{absenceId}', defaults: ['absenceId' => null], methods: ['GET'])]
    public function delivery(?string $absenceId): JsonResponse
    {
        try {
            $report = $this->notifications->deliveryReport(null === $absenceId ? null : Ulid::fromString($absenceId));
            $report['items'] = array_map(function (array $item): array {
                $capabilities = null;
                if (null !== $item['provider']) {
                    try {
                        $capabilities = $this->providers->get(CommunicationProvider::from($item['provider']))->capabilities();
                    } catch (\DomainException) {
                        // A historical failed row must remain visible even while its adapter is unavailable.
                    }
                }
                $item['capabilities'] = [
                    'supportsDeliveredStatus' => $capabilities?->supportsDeliveredStatus ?? false,
                    'supportsReadStatus' => $capabilities?->supportsReadStatus ?? false,
                ];

                return $item;
            }, $report['items']);

            return new JsonResponse($report);
        } catch (\InvalidArgumentException|\ValueError) {
            return new JsonResponse(['message' => 'Отчёт о доставке не найден.'], 404);
        }
    }
}
