<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'schedule_generation_issues')]
#[ORM\UniqueConstraint(name: 'uniq_schedule_generation_issue_occurrence', columns: ['organization_id', 'regular_schedule_id', 'occurrence_date'])]
#[ORM\UniqueConstraint(name: 'uniq_schedule_generation_issues_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\Index(name: 'idx_schedule_generation_issues_open', columns: ['organization_id', 'status', 'occurrence_date'])]
final class ScheduleGenerationIssue implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'regular_schedule_id', type: 'ulid')]
        private Ulid $regularScheduleId,
        #[ORM\Column(name: 'regular_schedule_day_id', type: 'ulid')]
        private Ulid $regularScheduleDayId,
        #[ORM\Column(name: 'occurrence_date', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $occurrenceDate,
        #[ORM\Column(name: 'conflict_code', length: 48)]
        private string $conflictCode,
        #[ORM\Column(name: 'conflict_message', length: 300)]
        private string $conflictMessage,
        #[ORM\Column(length: 16)]
        private string $status,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'resolved_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $resolvedAt,
    ) {
    }

    public static function open(RegularSchedule $schedule, RegularScheduleDay $day, \DateTimeImmutable $date, string $code, string $message): self
    {
        return new self(
            new Ulid(),
            $schedule->organization(),
            $schedule->id(),
            $day->id(),
            new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC')),
            $code,
            $message,
            'OPEN',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            null,
        );
    }

    public function refresh(RegularScheduleDay $day, string $code, string $message): void
    {
        if (!$this->regularScheduleId->equals($day->regularScheduleId())) {
            throw new \LogicException('Cannot move a generation issue to another regular schedule.');
        }
        $this->regularScheduleDayId = $day->id();
        $this->conflictCode = $code;
        $this->conflictMessage = $message;
        $this->status = 'OPEN';
        $this->resolvedAt = null;
    }

    public function resolve(): void
    {
        if ('RESOLVED' !== $this->status) {
            $this->status = 'RESOLVED';
            $this->resolvedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function regularScheduleId(): Ulid { return $this->regularScheduleId; }
    public function regularScheduleDayId(): Ulid { return $this->regularScheduleDayId; }
    public function occurrenceDate(): \DateTimeImmutable { return $this->occurrenceDate; }
    public function conflictCode(): string { return $this->conflictCode; }
    public function conflictMessage(): string { return $this->conflictMessage; }
    public function status(): string { return $this->status; }
}
