<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\Model\RegularSchedule;
use App\Module\Scheduling\Domain\Model\RegularScheduleDay;
use App\Module\Scheduling\Domain\Model\ScheduleGenerationIssue;
use Symfony\Component\Uid\Ulid;

interface RegularScheduleStore
{
    /** @return list<RegularSchedule> */
    public function all(): array;

    public function find(Ulid $id): ?RegularSchedule;

    /** @return list<RegularScheduleDay> */
    public function days(Ulid $scheduleId, bool $includeInactive = false): array;

    public function findDay(Ulid $scheduleId, Ulid $dayId): ?RegularScheduleDay;

    /** @return list<ScheduleGenerationIssue> */
    public function openIssues(?Ulid $scheduleId = null): array;

    public function findIssue(Ulid $issueId): ?ScheduleGenerationIssue;

    public function findIssueForOccurrence(Ulid $scheduleId, \DateTimeImmutable $date): ?ScheduleGenerationIssue;

    public function save(RegularSchedule|RegularScheduleDay|ScheduleGenerationIssue ...$entities): void;

    public function transactional(callable $operation): mixed;
}
