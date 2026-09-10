<?php

declare(strict_types=1);

namespace App\Module\Communications\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'confirmation_settings')]
#[ORM\UniqueConstraint(name: 'uniq_confirmation_settings_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_confirmation_settings_organization', columns: ['organization_id'])]
final class ConfirmationSettings implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\OneToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(name: 'organization_id', nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'request_time', length: 5)]
        private string $requestTime,
        #[ORM\Column(name: 'no_response_time', length: 5)]
        private string $noResponseTime,
        #[ORM\Column(name: 'reminder_enabled')]
        private bool $reminderEnabled,
        #[ORM\Column(name: 'reminder_lead_minutes')]
        private int $reminderLeadMinutes,
        #[ORM\Column(name: 'reminder_not_before', length: 5)]
        private string $reminderNotBefore,
        #[ORM\Column(name: 'quiet_hours_start', length: 5)]
        private string $quietHoursStart,
        #[ORM\Column(name: 'quiet_hours_end', length: 5)]
        private string $quietHoursEnd,
    ) {
    }

    public static function defaults(Organization $organization): self
    {
        return new self(new Ulid(), $organization, '14:00', '16:00', true, 120, '07:00', '21:00', '07:00');
    }

    public function change(
        string $requestTime,
        string $noResponseTime,
        bool $reminderEnabled,
        int $reminderLeadMinutes,
        string $reminderNotBefore,
        string $quietHoursStart,
        string $quietHoursEnd,
    ): void {
        foreach ([$requestTime, $noResponseTime, $reminderNotBefore, $quietHoursStart, $quietHoursEnd] as $time) {
            self::assertTime($time);
        }
        if ($noResponseTime <= $requestTime) {
            throw new \InvalidArgumentException('Время сообщения о неответивших должно быть позже времени запроса.');
        }
        if (15 > $reminderLeadMinutes || 1440 < $reminderLeadMinutes) {
            throw new \InvalidArgumentException('Повторное напоминание задаётся от 15 минут до 24 часов.');
        }
        if ($quietHoursStart === $quietHoursEnd) {
            throw new \InvalidArgumentException('Начало и конец тихих часов должны различаться.');
        }

        $this->requestTime = $requestTime;
        $this->noResponseTime = $noResponseTime;
        $this->reminderEnabled = $reminderEnabled;
        $this->reminderLeadMinutes = $reminderLeadMinutes;
        $this->reminderNotBefore = $reminderNotBefore;
        $this->quietHoursStart = $quietHoursStart;
        $this->quietHoursEnd = $quietHoursEnd;
    }

    public function organizationId(): Ulid { return $this->organization->id(); }
    public function requestTime(): string { return $this->requestTime; }
    public function noResponseTime(): string { return $this->noResponseTime; }
    public function reminderEnabled(): bool { return $this->reminderEnabled; }
    public function reminderLeadMinutes(): int { return $this->reminderLeadMinutes; }
    public function reminderNotBefore(): string { return $this->reminderNotBefore; }
    public function quietHoursStart(): string { return $this->quietHoursStart; }
    public function quietHoursEnd(): string { return $this->quietHoursEnd; }

    public function isQuietAt(\DateTimeImmutable $localNow): bool
    {
        $time = $localNow->format('H:i');

        return $this->quietHoursStart < $this->quietHoursEnd
            ? $time >= $this->quietHoursStart && $time < $this->quietHoursEnd
            : $time >= $this->quietHoursStart || $time < $this->quietHoursEnd;
    }

    private static function assertTime(string $time): void
    {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
            throw new \InvalidArgumentException('Время должно быть указано в формате ЧЧ:ММ.');
        }
    }
}
