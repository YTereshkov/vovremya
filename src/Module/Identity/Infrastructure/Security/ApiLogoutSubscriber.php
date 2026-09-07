<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class ApiLogoutSubscriber implements EventSubscriberInterface
{
    public function onLogout(LogoutEvent $event): void
    {
        $event->setResponse(new Response(status: Response::HTTP_NO_CONTENT));
    }

    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => ['onLogout', 128]];
    }
}
