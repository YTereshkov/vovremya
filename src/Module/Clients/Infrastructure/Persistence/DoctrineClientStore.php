<?php

declare(strict_types=1);

namespace App\Module\Clients\Infrastructure\Persistence;

use App\Module\Clients\Application\ChannelConnectionResolver;
use App\Module\Clients\Application\ClientStore;
use App\Module\Clients\Application\NotificationRecipient;
use App\Module\Clients\Application\NotificationRecipientResolver;
use App\Module\Clients\Application\WaitingClientProfile;
use App\Module\Clients\Application\WaitingClientReader;
use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Clients\Domain\Model\ClientAbsence;
use App\Module\Clients\Domain\Model\ContactPerson;
use App\Module\Organization\Application\OrganizationContext;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineClientStore implements ClientStore, ChannelConnectionResolver, NotificationRecipientResolver, WaitingClientReader
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationContext $organizationContext)
    {
    }

    public function all(?string $search = null): array
    {
        $query = $this->query(Client::class)->orderBy('item.name', 'ASC')->addOrderBy('item.id', 'ASC');
        if (null !== $search && '' !== trim($search)) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($search));
            $query->andWhere("LOWER(item.name) LIKE LOWER(:search) ESCAPE '!'")->setParameter('search', '%'.$escaped.'%');
        }

        return $query->getQuery()->getResult();
    }

    public function find(Ulid $id): ?Client
    {
        $result = $this->query(Client::class)->andWhere('item.id = :id')->setParameter('id', $id, 'ulid')->getQuery()->getOneOrNullResult();

        return $result instanceof Client ? $result : null;
    }

    public function absences(?Ulid $clientId = null): array
    {
        return $this->children(ClientAbsence::class, $clientId)
            ->orderBy('item.startsOn', 'DESC')->addOrderBy('item.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function contacts(?Ulid $clientId = null): array
    {
        return $this->children(ContactPerson::class, $clientId)->orderBy('item.name', 'ASC')->addOrderBy('item.id', 'ASC')->getQuery()->getResult();
    }

    public function findContact(Ulid $clientId, Ulid $contactId): ?ContactPerson
    {
        $result = $this->children(ContactPerson::class, $clientId)
            ->andWhere('item.id = :id')->setParameter('id', $contactId, 'ulid')->getQuery()->getOneOrNullResult();

        return $result instanceof ContactPerson ? $result : null;
    }

    public function channels(?Ulid $clientId = null): array
    {
        return $this->children(ChannelConnection::class, $clientId)->orderBy('item.createdAt', 'ASC')->addOrderBy('item.id', 'ASC')->getQuery()->getResult();
    }

    public function findChannel(Ulid $clientId, Ulid $channelId): ?ChannelConnection
    {
        $result = $this->children(ChannelConnection::class, $clientId)
            ->andWhere('item.id = :id')->setParameter('id', $channelId, 'ulid')->getQuery()->getOneOrNullResult();

        return $result instanceof ChannelConnection ? $result : null;
    }

    public function findChannelForTenant(Ulid $id): ?ChannelConnection
    {
        $result = $this->query(ChannelConnection::class)
            ->andWhere('item.id = :id')->setParameter('id', $id, 'ulid')
            ->getQuery()->getOneOrNullResult();

        return $result instanceof ChannelConnection ? $result : null;
    }

    public function findChannelByRoutingKey(string $provider, string $routingKey): ?ChannelConnection
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('item')->from(ChannelConnection::class, 'item')
            ->andWhere('item.provider = :provider')
            ->andWhere('item.webhookRoutingKey = :routingKey')
            ->setParameter('provider', $provider)
            ->setParameter('routingKey', $routingKey)
            ->getQuery()->getOneOrNullResult();

        return $result instanceof ChannelConnection ? $result : null;
    }

    public function activatePendingChannelForTenant(Ulid $id, string $token, string $address): ?ChannelConnection
    {
        $address = trim($address);
        if ('' === $address || 254 < mb_strlen($address)) {
            return null;
        }
        $affected = $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE channel_connections
                SET address = :address,
                    active = TRUE,
                    verified = TRUE,
                    activation_token_hash = NULL,
                    activation_expires_at = NULL
                WHERE organization_id = :organization
                  AND id = :id
                  AND active = FALSE
                  AND verified = FALSE
                  AND activation_token_hash = :token_hash
                  AND activation_expires_at >= clock_timestamp()
                SQL,
            [
                'address' => $address,
                'organization' => $this->organizationContext->currentId()->toRfc4122(),
                'id' => $id->toRfc4122(),
                'token_hash' => hash('sha256', $token),
            ],
        );
        if (1 !== $affected) {
            return null;
        }

        $channel = $this->findChannelForTenant($id);
        if (null !== $channel) {
            $this->entityManager->refresh($channel);
        }

        return $channel;
    }

    public function primaryForClient(Ulid $clientId): ?NotificationRecipient
    {
        $client = $this->find($clientId);
        if (null === $client || null === $client->primaryChannelId()) {
            return null;
        }

        return $this->byChannel($client->id(), $client->primaryChannelId());
    }

    public function byChannel(Ulid $clientId, Ulid $channelConnectionId): ?NotificationRecipient
    {
        $client = $this->find($clientId);
        if (null === $client) {
            return null;
        }
        $channel = $this->findChannel($client->id(), $channelConnectionId);
        if (null === $channel || !$channel->isActive()) {
            return null;
        }
        $contactName = null;
        if (null !== $channel->contactPersonId()) {
            $contactName = $this->findContact($client->id(), $channel->contactPersonId())?->name();
            if (null === $contactName) {
                return null;
            }
        }

        return new NotificationRecipient(
            $channel->id(),
            $channel->provider(),
            $channel->address(),
            $client->name(),
            $contactName,
        );
    }

    public function availableProfiles(array $clientIds, \DateTimeImmutable $localDate): array
    {
        if ([] === $clientIds) {
            return [];
        }
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT client.id, client.name
                FROM clients client
                WHERE client.organization_id = :organization
                  AND client.id IN (:client_ids)
                  AND NOT EXISTS (
                      SELECT 1
                      FROM client_absences absence
                      WHERE absence.organization_id = client.organization_id
                        AND absence.client_id = client.id
                        AND absence.starts_on <= :local_date
                        AND absence.ends_on >= :local_date
                  )
                ORDER BY client.name, client.id
                SQL,
            [
                'organization' => $this->organizationContext->currentId()->toRfc4122(),
                'client_ids' => array_map(static fn (Ulid $id): string => $id->toRfc4122(), $clientIds),
                'local_date' => $localDate->format('Y-m-d'),
            ],
            ['client_ids' => ArrayParameterType::STRING],
        );
        $profiles = [];
        foreach ($rows as $row) {
            $profile = new WaitingClientProfile(Ulid::fromString((string) $row['id']), (string) $row['name']);
            $profiles[$profile->id->toRfc4122()] = $profile;
        }

        return $profiles;
    }

    public function save(OrganizationOwned ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->assertScope($entity);
            $this->entityManager->persist($entity);
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new \DomainException('Такая запись уже существует.', 0, $exception);
        }
    }

    public function remove(OrganizationOwned $entity): void
    {
        $this->assertScope($entity);
        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }

    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->wrapInTransaction($operation);
    }

    private function children(string $class, ?Ulid $clientId): QueryBuilder
    {
        $query = $this->query($class);
        if (null !== $clientId) {
            $query->andWhere('item.clientId = :client')->setParameter('client', $clientId, 'ulid');
        }

        return $query;
    }

    private function query(string $class): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('item')
            ->from($class, 'item')
            ->andWhere('IDENTITY(item.organization) = :organization')
            ->setParameter('organization', $this->organizationContext->currentId(), 'ulid');
    }

    private function assertScope(OrganizationOwned $entity): void
    {
        if (!OrganizationIsolation::belongsTo($this->organizationContext->currentId(), $entity)) {
            throw new \LogicException('Cannot write outside the current organization.');
        }
    }
}
