<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Communications\Domain\Model\NormalizedWebhookEvent;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Symfony\Component\Uid\Ulid;

interface NormalizedWebhookEventStore
{
    /** @return list<NormalizedWebhookEvent> */
    public function pending(int $limit = 50): array;

    public function claim(Ulid $id): ?NormalizedWebhookEvent;

    public function find(Ulid $id): ?NormalizedWebhookEvent;

    public function save(OrganizationOwned ...$entities): void;

    /** @param callable(): void $operation */
    public function completeAtomically(NormalizedWebhookEvent $event, callable $operation): void;

    public function recordProcessingFailure(Ulid $id, int $attempt, string $error, int $maxAttempts = 3): void;
}
