<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Persistence;

use App\Module\Communications\Application\MessageTemplateStore;
use App\Module\Communications\Domain\Model\MessageTemplateType;
use App\Module\Communications\Domain\Model\OrganizationMessageTemplate;
use App\Module\Organization\Application\OrganizationContext;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineMessageTemplateStore implements MessageTemplateStore
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationContext $organizationContext)
    {
    }

    public function all(): array
    {
        return $this->query()->orderBy('template.type', 'ASC')->getQuery()->getResult();
    }

    public function find(MessageTemplateType $type): ?OrganizationMessageTemplate
    {
        $result = $this->query()->andWhere('template.type = :type')->setParameter('type', $type->value)->getQuery()->getOneOrNullResult();

        return $result instanceof OrganizationMessageTemplate ? $result : null;
    }

    public function save(OrganizationMessageTemplate $template): void
    {
        $this->assertScope($template);
        $this->entityManager->persist($template);
        $this->entityManager->flush();
    }

    public function remove(OrganizationMessageTemplate $template): void
    {
        $this->assertScope($template);
        $this->entityManager->remove($template);
        $this->entityManager->flush();
    }

    private function query(): \Doctrine\ORM\QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('template')->from(OrganizationMessageTemplate::class, 'template')
            ->andWhere('IDENTITY(template.organization) = :organization')
            ->setParameter('organization', $this->organizationContext->currentId(), 'ulid');
    }

    private function assertScope(OrganizationMessageTemplate $template): void
    {
        if (!OrganizationIsolation::belongsTo($this->organizationContext->currentId(), $template)) {
            throw new \LogicException('Cannot write message template outside the current organization.');
        }
    }
}
