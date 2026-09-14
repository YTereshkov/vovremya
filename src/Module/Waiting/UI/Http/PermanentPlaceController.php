<?php

declare(strict_types=1);

namespace App\Module\Waiting\UI\Http;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Waiting\Application\PermanentPlaceManager;
use App\Module\Waiting\Application\PermanentPlaceMatchingService;
use App\Module\Waiting\Application\PermanentPlaceOfferService;
use App\Module\Waiting\Application\PermanentPlaceOfferStore;
use App\Module\Waiting\Domain\Model\PermanentPlace;
use App\Module\Waiting\Domain\Model\PermanentPlaceSlot;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Ulid;

final readonly class PermanentPlaceController
{
    public function __construct(
        private PermanentPlaceManager $places,
        private PermanentPlaceMatchingService $matching,
        private PermanentPlaceOfferStore $offers,
        private PermanentPlaceOfferService $offerService,
    ) {
    }

    #[Route('/api/permanent-places', methods: ['GET'])]
    public function list(#[CurrentUser] AdministratorAccount $actor): JsonResponse
    {
        $items = $this->places->openWithSlots(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $places = array_map(static fn (array $item): PermanentPlace => $item['place'], $items);
        $offers = $this->offers->activeForPlaces(array_map(static fn (PermanentPlace $place): Ulid => $place->id(), $places));

        return new JsonResponse(array_map(function (array $item) use ($offers): array {
            /** @var PermanentPlace $place */
            $place = $item['place'];
            $offer = $offers[$place->id()->toRfc4122()] ?? null;

            return [
                'id' => $place->id()->toRfc4122(),
                'type' => $place->type()->value,
                'specialistId' => $place->specialistId()->toRfc4122(),
                'service' => ['id' => $place->serviceId()->toRfc4122(), 'name' => $place->serviceName()],
                'availableFrom' => $place->availableFrom()->format('Y-m-d'),
                'slots' => array_map(static fn (PermanentPlaceSlot $slot): array => [
                    'weekday' => $slot->weekday(),
                    'startTime' => $slot->startTime(),
                    'durationMinutes' => $slot->durationMinutes(),
                ], $item['slots']),
                'activeOffer' => null === $offer ? null : $this->offerService->present($offer),
            ];
        }, $items));
    }

    #[Route('/api/permanent-places/{id}/candidates', methods: ['GET'])]
    public function candidates(string $id): JsonResponse
    {
        try {
            return new JsonResponse($this->matching->candidates(Ulid::fromString($id)));
        } catch (\InvalidArgumentException|\OutOfBoundsException) {
            return new JsonResponse(['message' => 'Постоянное место не найдено.'], 404);
        }
    }
}
