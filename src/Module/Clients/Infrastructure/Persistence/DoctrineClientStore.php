<?php

declare(strict_types=1);

namespace App\Module\Clients\Infrastructure\Persistence;

use App\Module\Clients\Application\ClientStore;
use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Clients\Domain\Model\ContactPerson;
use App\Module\Organization\Application\OrganizationContext;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineClientStore implements ClientStore
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
