<?php

declare(strict_types=1);

namespace App\Module\Clients\Application;

interface ChannelActivationLinkFactory
{
    public function create(string $provider, string $token): string;
}
