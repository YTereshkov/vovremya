<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

final readonly class ChannelCapabilities
{
    public function __construct(
        public bool $supportsButtons,
        public bool $supportsMessageEdit,
        public bool $supportsDeliveredStatus,
        public bool $supportsReadStatus,
        public bool $supportsDeepLink,
    ) {
    }
}
