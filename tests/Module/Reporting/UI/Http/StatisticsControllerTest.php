<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\UI\Http;

use App\Module\Catalog\Domain\Model\Service;
use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentConfirmationRequest;
use App\Module\Scheduling\Domain\Model\AppointmentResultStatus;
use App\Module\Scheduling\Domain\Model\TransferRequest;
use App\Module\Waiting\Domain\Model\FreeWindow;
use App\Module\Waiting\Domain\Model\FreeWindowOffer;
use App\Module\Waiting\Domain\Model\FreeWindowOfferTargetType;
use App\Module\Waiting\Domain\Model\WaitingListEntry;
use App\Module\Workforce\Domain\Model\Specialist;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;

final class StatisticsControllerTest extends WebTestCase
{
    private KernelBrowser $browser;
    private EntityManagerInterface $entityManager;
    private AdministratorAccount $administrator;
    private Specialist $specialist;
    private Specialist $secondSpecialist;
    private Client $client;
    private Service $service;
    private ChannelConnection $channel;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)->create(
            'Statistics',
            'statistics-'.bin2hex(random_bytes(4)).'@example.test',
            'test-password',
            'Europe/Moscow',
        );
        $this->specialist = new Specialist(new Ulid(), $this->administrator->organization(), 'Анна Алексеева', 'Логопед');
        $this->secondSpecialist = new Specialist(new Ulid(), $this->administrator->organization(), 'Борис Иванов', 'Психолог');
        $this->client = Client::create($this->administrator->organization(), 'Петя Иванов', 'CHILD', '+79990000000', null);
        $this->service = Service::create($this->administrator->organization(), 'Занятие', 45, null, null);
        foreach ([$this->specialist, $this->secondSpecialist, $this->client, $this->service] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->channel = ChannelConnection::create($this->client, null, 'MAX', 'max-user');
        $this->channel->activate();
        $this->entityManager->persist($this->channel);
        $this->entityManager->flush();
        $this->browser->loginUser($this->administrator);
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        $this->entityManager->clear();
        parent::tearDown();
    }

    public function testReturnsTenantScopedMonthlyStatisticsAndSpecialistFilter(): void
    {
        $conducted = $this->appointment($this->specialist, '2026-09-02T10:00:00+03:00');
        $conducted->recordResult(AppointmentResultStatus::Conducted, new \DateTimeImmutable('2026-09-02T11:00:00+03:00'), 12, false, null);
        $early = $this->appointment($this->specialist, '2026-09-03T10:00:00+03:00');
        $early->recordResult(AppointmentResultStatus::CancelledByClient, new \DateTimeImmutable('2026-09-01T10:00:00+03:00'), 12, false, null);
        $late = $this->appointment($this->specialist, '2026-09-04T10:00:00+03:00');
        $late->recordResult(AppointmentResultStatus::CancelledByClient, new \DateTimeImmutable('2026-09-04T09:00:00+03:00'), 12, false, null);
        $cancelled = $this->appointment($this->specialist, '2026-09-05T10:00:00+03:00');
        $cancelled->recordResult(AppointmentResultStatus::CancelledBySpecialist, new \DateTimeImmutable('2026-09-04T10:00:00+03:00'), 12, false, null);
        $confirmedNoShow = $this->appointment($this->specialist, '2026-09-06T10:00:00+03:00');
        $confirmedNoShow->recordResult(AppointmentResultStatus::NoShow, new \DateTimeImmutable('2026-09-06T11:00:00+03:00'), 12, false, null);
        $unconfirmedNoShow = $this->appointment($this->specialist, '2026-09-07T10:00:00+03:00');
        $unconfirmedNoShow->recordResult(AppointmentResultStatus::NoShow, new \DateTimeImmutable('2026-09-07T11:00:00+03:00'), 12, false, null);
        $noRequestNoShow = $this->appointment($this->specialist, '2026-09-07T12:00:00+03:00');
        $noRequestNoShow->recordResult(AppointmentResultStatus::NoShow, new \DateTimeImmutable('2026-09-07T13:00:00+03:00'), 12, false, null);
        $transferSource = $this->appointment($this->specialist, '2026-09-08T10:00:00+03:00');
        $transferTarget = $this->appointment($this->specialist, '2026-09-09T10:00:00+03:00');
        $secondSpecialistAppointment = $this->appointment($this->secondSpecialist, '2026-09-10T10:00:00+03:00');
        $secondSpecialistAppointment->recordResult(AppointmentResultStatus::Conducted, new \DateTimeImmutable('2026-09-10T11:00:00+03:00'), 12, false, null);
        $monthStart = $this->appointment($this->specialist, '2026-09-01T00:00:00+03:00');
        $previousMonth = $this->appointment($this->specialist, '2026-08-31T23:59:00+03:00');
        $nextMonth = $this->appointment($this->specialist, '2026-10-01T00:30:00+03:00');
        foreach ([$conducted, $early, $late, $cancelled, $confirmedNoShow, $unconfirmedNoShow, $noRequestNoShow, $transferSource, $transferTarget, $secondSpecialistAppointment, $monthStart, $previousMonth, $nextMonth] as $appointment) {
            $this->entityManager->persist($appointment);
        }
        $this->entityManager->flush();

        $confirmed = AppointmentConfirmationRequest::create($confirmedNoShow, $this->channel->id(), new \DateTimeImmutable('2026-09-05T10:00:00+03:00'));
        $noResponse = AppointmentConfirmationRequest::create($unconfirmedNoShow, $this->channel->id(), new \DateTimeImmutable('2026-09-06T10:00:00+03:00'));
        $noResponse->markNoResponse(new \DateTimeImmutable('2026-09-06T18:00:00+03:00'));
        $completedTransfer = TransferRequest::open($transferSource, $this->channel->id(), new \DateTimeImmutable('2026-09-01T10:00:00+03:00'));
        $completedTransfer->offer('transfer-decline-token', new \DateTimeImmutable('2026-09-01T11:00:00+03:00'));
        $completedTransfer->complete($transferTarget->id(), new \DateTimeImmutable('2026-09-01T12:00:00+03:00'));
        $transferSource->recordRescheduled(new \DateTimeImmutable('2026-09-01T12:00:00+03:00'));
        $openTransfer = TransferRequest::open($transferTarget, $this->channel->id(), new \DateTimeImmutable('2026-09-02T10:00:00+03:00'));
        foreach ([$confirmed, $noResponse, $completedTransfer, $openTransfer] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE appointment_confirmation_requests SET status = 'CONFIRMED', responded_at = '2026-09-05 12:00:00+03' WHERE id = :id",
            ['id' => $confirmed->id()->toRfc4122()],
        );

        $firstWindow = FreeWindow::create($this->administrator->organization(), $early->id(), $this->specialist->id(), $this->service->id(), $this->service->name(), 45, $early->startsAt(), $early->endsAt(), new \DateTimeImmutable('2026-09-01T10:00:00+03:00'));
        $secondWindow = FreeWindow::create($this->administrator->organization(), $late->id(), $this->specialist->id(), $this->service->id(), $this->service->name(), 45, $late->startsAt(), $late->endsAt(), new \DateTimeImmutable('2026-09-01T10:00:00+03:00'));
        $waiting = WaitingListEntry::create($this->client, $this->service->id(), $this->specialist->id(), 1, true, new \DateTimeImmutable('2026-09-01'), null, new \DateTimeImmutable('2026-09-01T10:00:00+03:00'));
        foreach ([$firstWindow, $secondWindow, $waiting] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $offer = FreeWindowOffer::create($firstWindow, $this->client->id(), $this->client->name(), $this->channel->id(), FreeWindowOfferTargetType::WaitingList, null, $waiting->id(), 45, null, null, 45, 'accept-offer-token', 'decline-offer-token', new \DateTimeImmutable('2026-09-01T11:00:00+03:00'));
        $offer->accept($transferTarget->id(), new \DateTimeImmutable('2026-09-01T12:00:00+03:00'));
        $this->entityManager->persist($offer);
        $this->entityManager->flush();

        $this->browser->request('GET', '/api/statistics?month=2026-09');
        self::assertResponseIsSuccessful();
        $result = $this->json();
        self::assertSame('Europe/Moscow', $result['timezone']);
        self::assertSame(10, $result['appointments']['planned']);
        self::assertSame(2, $result['appointments']['conducted']);
        self::assertSame(1, $result['appointments']['cancelledByClientEarly']);
        self::assertSame(1, $result['appointments']['cancelledByClientLate']);
        self::assertSame(1, $result['appointments']['cancelledBySpecialist']);
        self::assertSame(3, $result['appointments']['noShows']);
        self::assertSame(['transferRequests' => 2, 'successfulTransfers' => 1], $result['scheduleChanges']);
        self::assertSame(['confirmed' => 1, 'noResponse' => 1, 'noShowsAfterConfirmation' => 1, 'noShowsWithoutConfirmation' => 2], $result['confirmations']);
        self::assertSame(['freeWindows' => 2, 'filledFromWaiting' => 1], $result['waitingList']);
        self::assertCount(2, $result['specialists']);

        $this->browser->request('GET', '/api/statistics?month=2026-09&specialistId='.$this->secondSpecialist->id()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['appointments']['planned']);
        self::assertSame(1, $this->json()['appointments']['conducted']);
    }

    public function testRejectsInvalidMonthAndForeignSpecialistWithoutLeakingData(): void
    {
        $foreign = self::getContainer()->get(CreateAdministratorHandler::class)->create(
            'Foreign statistics',
            'foreign-statistics-'.bin2hex(random_bytes(4)).'@example.test',
            'test-password',
            'UTC',
        );
        $foreignSpecialist = new Specialist(new Ulid(), $foreign->organization(), 'Чужой специалист', 'Логопед');
        $this->entityManager->persist($foreignSpecialist);
        $this->entityManager->flush();

        $this->browser->request('GET', '/api/statistics?month=2026-13');
        self::assertResponseStatusCodeSame(422);
        $this->browser->request('GET', '/api/statistics?month=2026-09&specialistId='.$foreignSpecialist->id()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/api/statistics?month=2026-09&specialistId=not-an-ulid');
        self::assertResponseStatusCodeSame(404);
    }

    public function testForeignTenantReceivesOnlyItsOwnTotals(): void
    {
        $appointment = $this->appointment($this->specialist, '2026-09-15T10:00:00+03:00');
        $this->entityManager->persist($appointment);
        $this->entityManager->flush();
        $foreign = self::getContainer()->get(CreateAdministratorHandler::class)->create(
            'Foreign totals',
            'foreign-totals-'.bin2hex(random_bytes(4)).'@example.test',
            'test-password',
            'Europe/Moscow',
        );

        $this->browser->loginUser($foreign);
        $this->browser->request('GET', '/api/statistics?month=2026-09');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json()['appointments']['planned']);
        self::assertSame([], $this->json()['specialists']);
    }

    private function appointment(Specialist $specialist, string $startsAt): Appointment
    {
        return Appointment::create(
            $this->administrator->organization(),
            $specialist->id(),
            $this->client->id(),
            $this->service->id(),
            $this->service->name(),
            $this->service->defaultDurationMinutes(),
            null,
            null,
            45,
            new \DateTimeImmutable($startsAt),
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
