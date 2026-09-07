<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\Infrastructure\Persistence;

use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Domain\Model\Organization;
use App\Module\Scheduling\Application\ScheduleAllocationStore;
use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use App\Module\Scheduling\Domain\TimeUnavailable;
use App\Module\Workforce\Domain\Model\Specialist;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;

final class ScheduleAllocationStoreTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ScheduleAllocationStore $store;
    private AdministratorAccount $administrator;
    private Specialist $specialist;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Allocation A', 'allocation-a@example.test', 'test-password', 'Europe/Moscow');
        $this->client->loginUser($this->administrator);
        $this->specialist = new Specialist(new Ulid(), $this->administrator->organization(), 'Юлия', 'Логопед');
        $this->entityManager->persist($this->specialist);
        $this->entityManager->flush();
        $this->store = self::getContainer()->get(ScheduleAllocationStore::class);
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        $this->entityManager->clear();
        parent::tearDown();
    }

    public function testDatabaseRejectsOverlapForSameSpecialist(): void
    {
        $this->store->save($this->allocation($this->specialist, '10:00', '11:00'));

        try {
            $this->store->save($this->allocation($this->specialist, '10:30', '11:30'));
            self::fail('Overlapping allocation was accepted.');
        } catch (TimeUnavailable $exception) {
            self::assertSame('TIME_ALREADY_UNAVAILABLE', $exception->conflict->code);
        }
    }

    public function testBoundariesAndDifferentSpecialistsDoNotConflict(): void
    {
        $other = new Specialist(new Ulid(), $this->administrator->organization(), 'Анна', 'Психолог');
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $this->store->save($this->allocation($this->specialist, '10:00', '11:00'));
        $this->store->save($this->allocation($this->specialist, '11:00', '12:00'));
        $this->store->save($this->allocation($other, '10:30', '11:30'));

        self::assertFalse($this->store->hasActiveConflict($this->specialist->id(), $this->instant('12:00'), $this->instant('13:00')));
        self::assertTrue($this->store->hasActiveConflict($this->specialist->id(), $this->instant('10:30'), $this->instant('10:45')));
    }

    public function testReleasedAllocationStopsBlockingTime(): void
    {
        $first = $this->allocation($this->specialist, '10:00', '11:00');
        $this->store->save($first);
        $first->release();
        $this->store->save($first);

        $this->store->save($this->allocation($this->specialist, '10:30', '11:30'));

        self::assertTrue($this->store->hasActiveConflict($this->specialist->id(), $this->instant('10:30'), $this->instant('11:30')));
    }

    public function testDatabaseRejectsSpecialistFromAnotherOrganization(): void
    {
        $foreignOrganization = Organization::create('Allocation B', 'Europe/Moscow');
        $foreignSpecialist = new Specialist(new Ulid(), $foreignOrganization, 'Мария', 'Психолог');
        $this->entityManager->persist($foreignOrganization);
        $this->entityManager->persist($foreignSpecialist);
        $this->entityManager->flush();

        $allocation = ScheduleAllocation::forAppointment(
            $this->administrator->organization(),
            $foreignSpecialist->id(),
            new Ulid(),
            $this->instant('10:00'),
            $this->instant('11:00'),
        );

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->store->save($allocation);
    }

    public function testStoreRejectsAllocationOwnedByAnotherOrganization(): void
    {
        $foreignOrganization = Organization::create('Allocation B', 'Europe/Moscow');
        $allocation = ScheduleAllocation::forAppointment(
            $foreignOrganization,
            $this->specialist->id(),
            new Ulid(),
            $this->instant('10:00'),
            $this->instant('11:00'),
        );

        $this->expectException(\LogicException::class);
        $this->store->save($allocation);
    }

    private function allocation(Specialist $specialist, string $start, string $end): ScheduleAllocation
    {
        return ScheduleAllocation::forAppointment(
            $specialist->organization(),
            $specialist->id(),
            new Ulid(),
            $this->instant($start),
            $this->instant($end),
        );
    }

    private function instant(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-07T'.$time.':00+03:00');
    }
}
