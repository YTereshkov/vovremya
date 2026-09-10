<?php

declare(strict_types=1);

namespace App\Module\Clients\Application;

use App\Module\Clients\Domain\Model\ChannelConnection;
use Symfony\Component\Uid\Ulid;

interface ChannelConnectionResolver
{
    public function findChannelForTenant(Ulid $id): ?ChannelConnection;

    public function findChannelByRoutingKey(string $provider, string $routingKey): ?ChannelConnection;

    public function activatePendingChannelForTenant(Ulid $id, string $token, string $address): ?ChannelConnection;
}
