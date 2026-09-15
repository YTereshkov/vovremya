<?php

declare(strict_types=1);

namespace App\Module\Clients\Application;

use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Clients\Domain\Model\ClientAbsence;
use App\Module\Clients\Domain\Model\ContactPerson;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use Symfony\Component\Uid\Ulid;

final readonly class ClientDirectory
{
    public function __construct(
        private ClientStore $store,
        private ChannelActivationLinkFactory $activationLinks,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(int:CHANNEL_ACTIVATION_TTL_SECONDS)%')]
        private int $activationTtlSeconds,
    )
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $search): array
    {
        $clients = $this->store->all($search);
        $contacts = $this->groupByClient($this->store->contacts());
        $channels = $this->groupByClient($this->store->channels());
        $absences = $this->groupByClient($this->store->absences());

        return array_map(fn (Client $client): array => $this->present(
            $client,
            $contacts[$client->id()->toRfc4122()] ?? [],
            $channels[$client->id()->toRfc4122()] ?? [],
            $absences[$client->id()->toRfc4122()] ?? [],
        ), $clients);
    }

    public function find(string $id): Client
    {
        return $this->store->find($this->id($id, 'Клиент не найден.')) ?? throw new \OutOfBoundsException('Клиент не найден.');
    }

    /** @param array<string, mixed>|null $contactInput
     *  @param array<string, mixed>|null $channelInput
     */
    public function create(
        AdministratorAccount $actor,
        string $name,
        string $type,
        ?string $phone,
        ?string $note,
        ?array $contactInput,
        ?array $channelInput,
    ): Client {
        $this->validateRequiredCommunication($type, $phone, $contactInput, $channelInput);

        return $this->store->transactional(function () use ($actor, $name, $type, $phone, $note, $contactInput, $channelInput): Client {
            $client = Client::create($actor->organization(), $name, $type, $phone, $note);
            $this->store->save($client);
            $contact = null;
            if (null !== $contactInput) {
                $contact = ContactPerson::create($client, $this->inputString($contactInput, 'name'), $this->inputOptionalString($contactInput, 'phone'));
                $this->store->save($contact);
            }
            if (null !== $channelInput) {
                $recipient = $this->inputString($channelInput, 'recipient');
                if ('CONTACT_PERSON' === $recipient && null === $contact) {
                    throw new \InvalidArgumentException('Добавьте контактное лицо для выбранного получателя.');
                }
                if (!in_array($recipient, ['CLIENT', 'CONTACT_PERSON'], true)) {
                    throw new \InvalidArgumentException('Выберите получателя канала.');
                }
                $channel = ChannelConnection::create(
                    $client,
                    'CONTACT_PERSON' === $recipient ? $contact : null,
                    $this->inputString($channelInput, 'provider'),
                    $this->inputString($channelInput, 'address'),
                );
                $this->store->save($channel);
                $client->selectPrimaryChannel($channel);
                $this->store->save($client);
            }

            return $client;
        });
    }

    /** @param array<string, mixed>|null $contactInput
     *  @param array<string, mixed>|null $channelInput
     */
    private function validateRequiredCommunication(string $type, ?string $phone, ?array $contactInput, ?array $channelInput): void
    {
        if (null === $channelInput) {
            throw new \InvalidArgumentException('Добавьте основной канал связи.');
        }

        $recipient = $this->inputString($channelInput, 'recipient');
        if ('CHILD' === $type) {
            if (null !== $phone && '' !== trim($phone)) {
                throw new \InvalidArgumentException('Для ребёнка укажите телефон родителя, а не клиента.');
            }
            if (null === $contactInput) {
                throw new \InvalidArgumentException('Укажите родителя ребёнка.');
            }
            $this->inputString($contactInput, 'name');
            $this->inputString($contactInput, 'phone');
            if ('CONTACT_PERSON' !== $recipient) {
                throw new \InvalidArgumentException('Получателем уведомлений ребёнка должен быть родитель.');
            }

            return;
        }

        if ('ADULT' === $type) {
            if (null === $phone || '' === trim($phone)) {
                throw new \InvalidArgumentException('Укажите телефон взрослого клиента.');
            }
            if (null !== $contactInput) {
                throw new \InvalidArgumentException('При создании взрослого клиента контактное лицо не требуется.');
            }
            if ('CLIENT' !== $recipient) {
                throw new \InvalidArgumentException('Получателем уведомлений должен быть сам взрослый клиент.');
            }
        }
    }

    public function update(Client $client, string $name, string $type, ?string $phone, ?string $note): void
    {
        $client->change($name, $type, $phone, $note);
        $this->store->save($client);
    }

    public function delete(Client $client): void
    {
        $this->store->transactional(function () use ($client): void {
            $client->selectPrimaryChannel(null);
            $this->store->save($client);
            $this->store->remove($client);
        });
    }

    public function addContact(Client $client, string $name, ?string $phone): ContactPerson
    {
        $contact = ContactPerson::create($client, $name, $phone);
        $this->store->save($contact);

        return $contact;
    }

    public function updateContact(Client $client, string $contactId, string $name, ?string $phone): void
    {
        $contact = $this->contact($client, $contactId);
        $contact->change($name, $phone);
        $this->store->save($contact);
    }

    public function removeContact(Client $client, string $contactId): void
    {
        $contact = $this->contact($client, $contactId);
        $this->store->transactional(function () use ($client, $contact): void {
            foreach ($this->store->channels($client->id()) as $channel) {
                if ($channel->contactPersonId()?->equals($contact->id())) {
                    if ($client->primaryChannelId()?->equals($channel->id())) {
                        $client->selectPrimaryChannel(null);
                        $this->store->save($client);
                    }
                    $this->store->remove($channel);
                }
            }
            $this->store->remove($contact);
        });
    }

    public function addChannel(Client $client, ?string $contactId, string $provider, string $address, bool $primary): ChannelConnection
    {
        $contact = null === $contactId ? null : $this->contact($client, $contactId);

        return $this->store->transactional(function () use ($client, $contact, $provider, $address, $primary): ChannelConnection {
            $channel = ChannelConnection::create($client, $contact, $provider, $address);
            $this->store->save($channel);
            if ($primary) {
                $client->selectPrimaryChannel($channel);
                $this->store->save($client);
            }

            return $channel;
        });
    }

    public function updateChannel(Client $client, string $channelId, string $provider, string $address): void
    {
        $channel = $this->channel($client, $channelId);
        $channel->change($provider, $address);
        $this->store->save($channel);
    }

    public function removeChannel(Client $client, string $channelId): void
    {
        $channel = $this->channel($client, $channelId);
        $this->store->transactional(function () use ($client, $channel): void {
            if ($client->primaryChannelId()?->equals($channel->id())) {
                $client->selectPrimaryChannel(null);
                $this->store->save($client);
            }
            $this->store->remove($channel);
        });
    }

    public function selectPrimaryChannel(Client $client, ?string $channelId): void
    {
        $client->selectPrimaryChannel(null === $channelId ? null : $this->channel($client, $channelId));
        $this->store->save($client);
    }

    public function configureChannelWebhookSecret(Client $client, string $channelId, string $secret): void
    {
        $channel = $this->channel($client, $channelId);
        $channel->configureWebhookSecret($secret);
        $this->store->save($channel);
    }

    /** @return array{url: string, expiresAt: string} */
    public function startChannelActivation(Client $client, string $channelId): array
    {
        $channel = $this->channel($client, $channelId);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $url = $this->activationLinks->create($channel->provider(), $token);
        $expiresAt = new \DateTimeImmutable(sprintf('+%d seconds', max(60, $this->activationTtlSeconds)), new \DateTimeZone('UTC'));
        $channel->startActivation($token, $expiresAt);
        $this->store->save($channel);

        return ['url' => $url, 'expiresAt' => $expiresAt->format(DATE_ATOM)];
    }

    public function deactivateChannel(Client $client, string $channelId): void
    {
        $channel = $this->channel($client, $channelId);
        $channel->deactivate();
        $this->store->save($channel);
    }

    /** @return array<string, mixed> */
    public function details(Client $client): array
    {
        return $this->present(
            $client,
            $this->store->contacts($client->id()),
            $this->store->channels($client->id()),
            $this->store->absences($client->id()),
        );
    }

    /** @param list<ContactPerson> $contacts
     *  @param list<ChannelConnection> $channels
     *  @param list<ClientAbsence> $absences
     *  @return array<string, mixed>
     */
    private function present(Client $client, array $contacts, array $channels, array $absences): array
    {
        $contactNames = [];
        foreach ($contacts as $contact) {
            $contactNames[$contact->id()->toRfc4122()] = $contact->name();
        }

        return [
            'id' => $client->id()->toRfc4122(),
            'name' => $client->name(),
            'type' => $client->type(),
            'phone' => $client->phone(),
            'note' => $client->note(),
            'primaryChannelId' => $client->primaryChannelId()?->toRfc4122(),
            'contacts' => array_map(static fn (ContactPerson $contact): array => [
                'id' => $contact->id()->toRfc4122(),
                'name' => $contact->name(),
                'phone' => $contact->phone(),
            ], $contacts),
            'channels' => array_map(static fn (ChannelConnection $channel): array => [
                'id' => $channel->id()->toRfc4122(),
                'provider' => $channel->provider(),
                'address' => $channel->address(),
                'status' => $channel->state(),
                'activationExpiresAt' => $channel->activationExpiresAt()?->format(DATE_ATOM),
                'recipientType' => null === $channel->contactPersonId() ? 'CLIENT' : 'CONTACT_PERSON',
                'recipientId' => $channel->contactPersonId()?->toRfc4122() ?? $client->id()->toRfc4122(),
                'recipientName' => null === $channel->contactPersonId()
                    ? $client->name()
                    : $contactNames[$channel->contactPersonId()->toRfc4122()] ?? '',
                'primary' => $client->primaryChannelId()?->equals($channel->id()) ?? false,
            ], $channels),
            'absences' => array_map(static fn (ClientAbsence $absence): array => [
                'id' => $absence->id()->toRfc4122(),
                'startsOn' => $absence->startsOn()->format('Y-m-d'),
                'endsOn' => $absence->endsOn()->format('Y-m-d'),
                'reason' => $absence->reason(),
                'mode' => $absence->mode()->value,
                'createFreeWindows' => $absence->createFreeWindows(),
                'notifyClient' => $absence->notifyClient(),
            ], $absences),
        ];
    }

    private function contact(Client $client, string $id): ContactPerson
    {
        return $this->store->findContact($client->id(), $this->id($id, 'Контактное лицо не найдено.'))
            ?? throw new \OutOfBoundsException('Контактное лицо не найдено.');
    }

    private function channel(Client $client, string $id): ChannelConnection
    {
        return $this->store->findChannel($client->id(), $this->id($id, 'Канал не найден.'))
            ?? throw new \OutOfBoundsException('Канал не найден.');
    }

    private function id(string $value, string $message): Ulid
    {
        try { return Ulid::fromString($value); }
        catch (\InvalidArgumentException) { throw new \OutOfBoundsException($message); }
    }

    /** @param array<string, mixed> $input */
    private function inputString(array $input, string $key): string
    {
        if (!is_string($input[$key] ?? null)) {
            throw new \InvalidArgumentException('Заполните обязательные поля.');
        }

        return $input[$key];
    }

    /** @param array<string, mixed> $input */
    private function inputOptionalString(array $input, string $key): ?string
    {
        if (null !== ($input[$key] ?? null) && !is_string($input[$key])) {
            throw new \InvalidArgumentException('Некорректное текстовое поле.');
        }

        return $input[$key] ?? null;
    }

    /** @param list<ContactPerson>|list<ChannelConnection> $items
     *  @return array<string, list<ContactPerson|ChannelConnection>>
     */
    private function groupByClient(array $items): array
    {
        $grouped = [];
        foreach ($items as $item) {
            $grouped[$item->clientId()->toRfc4122()][] = $item;
        }

        return $grouped;
    }
}
