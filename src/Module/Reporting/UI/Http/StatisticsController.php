<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Reporting\Application\StatisticsQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;

final readonly class StatisticsController
{
    public function __construct(private StatisticsQuery $statistics)
    {
    }

    #[Route('/api/statistics', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        $month = $request->query->get('month');
        $specialist = $request->query->get('specialistId');
        if (null !== $month && !is_string($month)) {
            return new JsonResponse(['message' => 'Укажите месяц в формате ГГГГ-ММ.'], 422);
        }
        if (null !== $specialist && !is_string($specialist)) {
            return new JsonResponse(['message' => 'Специалист не найден.'], 404);
        }

        try {
            $specialistId = null === $specialist || '' === $specialist ? null : Ulid::fromString($specialist);
        } catch (\InvalidArgumentException|\ValueError) {
            return new JsonResponse(['message' => 'Специалист не найден.'], 404);
        }

        try {
            return new JsonResponse($this->statistics->summary($month, $specialistId));
        } catch (\OutOfBoundsException) {
            return new JsonResponse(['message' => 'Специалист не найден.'], 404);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['message' => 'Укажите месяц в формате ГГГГ-ММ.'], 422);
        }
    }
}
