<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\Domain;

use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Domain\Model\OutboundMessage;
use App\Module\Organization\Domain\Model\Organization;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class OutboundMessageTest extends TestCase
{
    public function testRepeatedMarkSentWithoutIdentifierPreservesExistingIdentifier(): void
    {
        $message = $this->message();
        $message->markSent('provider-message-1');

        $message->markSent();

        self::assertSame('provider-message-1', $message->providerMessageId());
    }

    public function testRepeatedMarkSentWithSameIdentifierIsIdempotent(): void
    {
        $message = $this->message();
        $message->markSent('provider-message-1');

        $message->markSent('provider-message-1');

        self::assertSame('provider-message-1', $message->providerMessageId());
    }

    public function testRepeatedMarkSentRejectsConflictingIdentifier(): void
    {
        $message = $this->message();
        $message->markSent('provider-message-1');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Идентификатор сообщения провайдера уже назначен.');

        $message->markSent('provider-message-2');
    }

    private function message(): OutboundMessage
    {
        return OutboundMessage::create(
            Organization::create('Test', 'Europe/Moscow'),
            new Ulid(),
            new Ulid(),
            CommunicationProvider::MAX,
            'recipient-1',
            'Test message',
        );
    }
}
