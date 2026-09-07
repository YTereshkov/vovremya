<?php

declare(strict_types=1);

namespace App\Tests\Module\Clients\Domain;

use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Clients\Domain\Model\ContactPerson;
use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\CrossOrganizationAssociation;
use PHPUnit\Framework\TestCase;

final class RecipientOwnershipTest extends TestCase
{
    public function testClientCanExistWithoutContactPersonAndReceiveChannel(): void
    {
        $client = Client::create(Organization::create('Test', 'Europe/Moscow'), 'Анна', 'ADULT', '+7 900 000-00-00', null);
        $channel = ChannelConnection::create($client, null, 'MAX', '+7 900 000-00-00');
        $client->selectPrimaryChannel($channel);

        self::assertNull($channel->contactPersonId());
        self::assertTrue($channel->id()->equals($client->primaryChannelId()));
    }

    public function testContactPersonCanReceiveChannel(): void
    {
        $client = Client::create(Organization::create('Test', 'Europe/Moscow'), 'Петя', 'CHILD', null, null);
        $contact = ContactPerson::create($client, 'Анна', '+7 900 000-00-00');
        $channel = ChannelConnection::create($client, $contact, 'TELEGRAM', '@anna');

        self::assertTrue($contact->id()->equals($channel->contactPersonId()));
    }

    public function testForeignTenantCannotBecomeRecipient(): void
    {
        $first = Client::create(Organization::create('First', 'Europe/Moscow'), 'Петя', 'CHILD', null, null);
        $second = Client::create(Organization::create('Second', 'Europe/Moscow'), 'Анна', 'ADULT', null, null);
        $foreignContact = ContactPerson::create($second, 'Мария', null);

        $this->expectException(CrossOrganizationAssociation::class);
        ChannelConnection::create($first, $foreignContact, 'MAX', '+7 900 000-00-00');
    }

    public function testAnotherClientsChannelCannotBecomePrimary(): void
    {
        $organization = Organization::create('Test', 'Europe/Moscow');
        $first = Client::create($organization, 'Петя', 'CHILD', null, null);
        $second = Client::create($organization, 'Анна', 'ADULT', null, null);
        $channel = ChannelConnection::create($second, null, 'MAX', '+7 900 000-00-00');

        $this->expectException(\InvalidArgumentException::class);
        $first->selectPrimaryChannel($channel);
    }
}
