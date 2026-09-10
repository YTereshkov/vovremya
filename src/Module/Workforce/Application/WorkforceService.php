<?php

declare(strict_types=1);

namespace App\Module\Workforce\Application;

use App\Module\Identity\Application\AdministratorAccountReader;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Workforce\Domain\Model\AdditionalWorkingDay;
use App\Module\Workforce\Domain\Model\Specialist;
use App\Module\Workforce\Domain\Model\SpecialistAbsence;
use Symfony\Component\Uid\Ulid;

final readonly class WorkforceService
{
    public function __construct(private WorkforceStore $store, private AdministratorAccountReader $administrators) {}

    public function find(string $id): Specialist
    {
        try { $id = Ulid::fromString($id); } catch (\InvalidArgumentException) { throw new \OutOfBoundsException('Специалист не найден.'); }

        return $this->store->find($id) ?? throw new \OutOfBoundsException('Специалист не найден.');
    }

    public function create(AdministratorAccount $actor, string $name, string $specialization, ?string $administratorId): Specialist
    {
        $specialist = new Specialist(new Ulid(), $actor->organization(), $name, $specialization);
        $this->link($specialist, $administratorId);
        $this->store->save($specialist);

        return $specialist;
    }

    public function update(Specialist $specialist, string $name, string $specialization, ?string $administratorId): void
    {
        $specialist->rename($name, $specialization);
        $this->link($specialist, $administratorId);
        $this->store->save($specialist);
    }

    public function saveWeek(Specialist $specialist, array $days): void
    {
        $specialist->setWeeklyHours($days);
        $this->store->save($specialist);
    }

    public function delete(Specialist $specialist): void { $this->store->remove($specialist); }

    public function saveAdditionalDay(Specialist $specialist, ?string $id, string $date, array $work): AdditionalWorkingDay
    {
        $day = null === $id ? new AdditionalWorkingDay(new Ulid(), $specialist, $date, $work) : $this->day($specialist, $id);
        if (null !== $id) { $day->change($date, $work); }
        $this->store->save($day);

        return $day;
    }

    public function deleteAdditionalDay(Specialist $specialist, string $id): void { $this->store->remove($this->day($specialist, $id)); }

    public function list(): array
    {
        $days = [];
        foreach ($this->store->additionalDays() as $day) { $days[$day->specialistId()->toRfc4122()][] = $day; }
        $absences = [];
        foreach ($this->store->absences() as $absence) { $absences[$absence->specialistId()->toRfc4122()][] = $absence; }

        return array_map(fn (Specialist $s): array => $this->present(
            $s,
            $days[$s->id()->toRfc4122()] ?? [],
            $absences[$s->id()->toRfc4122()] ?? [],
        ), $this->store->all());
    }

    public function details(Specialist $s): array { return $this->present($s, $this->store->additionalDays($s->id()), $this->store->absences($s->id())); }

    private function link(Specialist $specialist, ?string $id): void
    {
        $administrator = null;
        if (null !== $id) {
            try { $uid = Ulid::fromString($id); } catch (\InvalidArgumentException) { throw new \OutOfBoundsException('Администратор не найден.'); }
            $administrator = $this->administrators->findInCurrentOrganization($uid) ?? throw new \OutOfBoundsException('Администратор не найден.');
        }
        $specialist->linkAdministrator($administrator);
    }

    private function day(Specialist $specialist, string $id): AdditionalWorkingDay
    {
        foreach ($this->store->additionalDays($specialist->id()) as $day) {
            if ($day->id()->toRfc4122() === $id) { return $day; }
        }
        throw new \OutOfBoundsException('Рабочий день не найден.');
    }

    /** @param list<AdditionalWorkingDay> $days
     *  @param list<SpecialistAbsence> $absences
     */
    private function present(Specialist $s, array $days, array $absences): array
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone($s->organization()->timezone()));
        $work = $s->weeklyHours()[(int) $today->format('N') - 1]['work'];
        $todayIntervals = null === $work ? [] : [$work];
        foreach ($days as $day) {
            if ($day->date() === $today->format('Y-m-d')) { $todayIntervals[] = $day->work(); }
        }
        usort($todayIntervals, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return [
            'id' => $s->id()->toRfc4122(), 'name' => $s->name(), 'specialization' => $s->specialization(),
            'administratorId' => $s->administratorId()?->toRfc4122(), 'weeklyHours' => $s->weeklyHours(),
            'today' => $today->format('Y-m-d'), 'todayIntervals' => $todayIntervals,
            'additionalDays' => array_map(static fn (AdditionalWorkingDay $day): array => [
                'id' => $day->id()->toRfc4122(), 'date' => $day->date(), 'work' => $day->work(),
            ], $days),
            'absences' => array_map(static fn (SpecialistAbsence $absence): array => [
                'id' => $absence->id()->toRfc4122(),
                'type' => $absence->type()->value,
                'startsOn' => $absence->startsOn()->format('Y-m-d'),
                'endsOn' => $absence->endsOn()->format('Y-m-d'),
                'comment' => $absence->comment(),
                'notifyClients' => $absence->notifyClients(),
            ], $absences),
        ];
    }
}
