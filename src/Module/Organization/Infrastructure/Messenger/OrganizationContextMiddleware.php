<?php

declare(strict_types=1);

namespace App\Module\Organization\Infrastructure\Messenger;

use App\Module\Organization\Application\Exception\UnknownOrganization;
use App\Module\Organization\Application\OrganizationAwareMessage;
use App\Module\Organization\Application\OrganizationContext;
use App\Module\Organization\Application\OrganizationExistenceChecker;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class OrganizationContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private OrganizationContext $organizationContext,
        private OrganizationExistenceChecker $organizationExistenceChecker,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        if (!$message instanceof OrganizationAwareMessage) {
            return $stack->next()->handle($envelope, $stack);
        }

        return $this->organizationContext->runWith(
            $message->organizationId(),
            function () use ($envelope, $message, $stack): Envelope {
                if (!$this->organizationExistenceChecker->exists($message->organizationId())) {
                    throw new UnknownOrganization();
                }

                return $stack->next()->handle($envelope, $stack);
            },
        );
    }
}
