<?php

declare(strict_types=1);

namespace App\Module\Clients\Application;

use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Clients\Domain\Model\ContactPerson;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Symfony\Component\Uid\Ulid;

interface ClientStore
{
    /** @return list<Client> */
    public function all(?string $search = null): array;

    public function find(Ulid $id): ?Client;

    /** @return list<ContactPerson> */
    public function contacts(?Ulid $clientId = null): array;

    public function findContact(Ulid $clientId, Ulid $contactId): ?ContactPerson;

    /** @return list<ChannelConnection> */
    public function channels(?Ulid $clientId = null): array;

    public function findChannel(Ulid $clientId, Ulid $channelId): ?ChannelConnection;

    public function save(OrganizationOwned ...$entities): void;

    public function remove(OrganizationOwned $entity): void;

    public function transactional(callable $operation): mixed;
}
