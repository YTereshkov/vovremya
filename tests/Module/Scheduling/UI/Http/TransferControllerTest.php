<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\UI\Http;

use App\Module\Catalog\Domain\Model\Service;
use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Domain\Model\NormalizedWebhookEvent;
use App\Module\Communications\Domain\Model\OutboundMessage;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Application\ConfirmationResponseConsumer;
use App\Module\Scheduling\Application\ScheduleAllocationStore;
use App\Module\Scheduling\Application\TransferResponseConsumer;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\RegularSchedule;
use App\Module\Scheduling\Domain\Model\RegularScheduleDay;
use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use App\Module\Scheduling\Domain\Model\TransferOption;
use App\Module\Scheduling\Domain\Model\TransferRequest;
use App\Module\Workforce\Domain\Model\Specialist;
use App\Module\Workforce\Domain\Model\SpecialistWeeklyHours;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;

final class TransferControllerTest extends WebTestCase
{
    private KernelBrowser $browser;
    private EntityManagerInterface $entityManager;
    private AdministratorAccount $administrator;
    private Specialist $specialist;
    private Client $patient;
    private ChannelConnection $channel;
    private Appointment $appointment;
    private \DateTimeImmutable $sourceStart;
    private string $csrf;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Transfers', 'transfers-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'Europe/Moscow');
        $week = SpecialistWeeklyHours::emptyWeek();
        foreach ($week as $index => $day) {
            $week[$index] = ['weekday' => $day['weekday'], 'enabled' => true, 'work' => ['start' => '09:00', 'end' => '20:00'], 'lunch' => null];
        }
        $this->specialist = new Specialist(new Ulid(), $this->administrator->organization(), 'Юлия', 'Логопед');
        $this->specialist->setWeeklyHours($week);
        $this->patient = Client::create($this->administrator->organization(), 'Петя', 'CHILD', null, null);
        $service = Service::create($this->administrator->organization(), 'Логопедическое занятие', 60, null, null);
        $this->channel = ChannelConnection::create($this->patient, null, 'MAX', '123456789');
        $this->channel->activate();
        $this->sourceStart = new \DateTimeImmutable('next monday 15:00', new \DateTimeZone('Europe/Moscow'));
        $this->appointment = Appointment::create($this->administrator->organization(), $this->specialist->id(), $this->patient->id(), $service->id(), $service->name(), 60, null, null, 60, $this->sourceStart);
        foreach ([$this->specialist, $this->patient, $service, $this->appointment] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->entityManager->persist($this->channel);
        $this->entityManager->flush();
        $this->patient->selectPrimaryChannel($this->channel);
        $this->entityManager->flush();
        $this->browser->loginUser($this->administrator);
        $this->browser->request('GET', '/api/auth/csrf');
        $this->csrf = $this->json()['mutationToken'];
        self::getContainer()->get(ScheduleAllocationStore::class)->save(ScheduleAllocation::forAppointment(
            $this->administrator->organization(), $this->specialist->id(), $this->appointment->id(), $this->appointment->startsAt(), $this->appointment->endsAt(),
        ));
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testOptionsDoNotReserveAndSelectionMovesAppointmentAtomically(): void
    {
        $first = $this->sourceStart->modify('+30 minutes');
        $second = $this->sourceStart->modify('+1 day 1 hour');
        $response = $this->offer([$first, $second]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('OPTIONS_SENT', $response['request']['status']);
        self::assertCount(2, $response['options']);
        self::assertSame(1, $this->countRows('schedule_allocations'));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM schedule_allocations WHERE allocation_type = 'OFFER_RESERVATION'"));

        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([]);
        self::assertInstanceOf(OutboundMessage::class, $outbound);
        self::assertCount(3, $outbound->buttons());
        $action = $outbound->buttons()[0][0]['action'];
        self::assertStringStartsWith('transfer-option:', $action);
        self::getContainer()->get(TransferResponseConsumer::class)->consume($this->event($action, $this->channel->id(), 'select-transfer'));

        $this->entityManager->clear();
        $source = $this->entityManager->find(Appointment::class, $this->appointment->id());
        $request = $this->entityManager->getRepository(TransferRequest::class)->find($response['request']['id']);
        self::assertSame('RESCHEDULED', $source?->resultStatus()?->value);
        self::assertSame('COMPLETED', $request?->status()->value);
        self::assertNotNull($request?->newAppointmentId());
        $newAppointment = $this->entityManager->find(Appointment::class, $request?->newAppointmentId());
        self::assertSame($first->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::RFC3339), $newAppointment?->startsAt()->format(\DateTimeInterface::RFC3339));
        self::assertSame(2, $this->countRows('schedule_allocations'));
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM schedule_allocations WHERE released_at IS NULL'));
        self::assertSame(['TRANSFER_OPTIONS_SENT', 'APPOINTMENT_RESCHEDULED'], $this->sourceEventTypes());
        $payload = json_decode((string) $this->entityManager->getConnection()->fetchOne("SELECT payload FROM appointment_events WHERE event_type = 'APPOINTMENT_RESCHEDULED'"), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($request?->newAppointmentId()?->toRfc4122(), $payload['newAppointmentId']);
    }

    public function testSelectionRepeatsAvailabilityAndKeepsRequestOpenWhenTimeWasTaken(): void
    {
        $optionStart = $this->sourceStart->modify('+1 day');
        $response = $this->offer([$optionStart]);
        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([]);
        self::assertInstanceOf(OutboundMessage::class, $outbound);

        $source = $this->entityManager->find(Appointment::class, $this->appointment->id());
        self::assertInstanceOf(Appointment::class, $source);
        $competitor = Appointment::create(
            $source->organization(), $source->specialistId(), $source->clientId(), $source->serviceId(),
            $source->serviceName(), 60, null, null, 60, $optionStart,
        );
        $this->entityManager->persist($competitor);
        $this->entityManager->flush();
        self::getContainer()->get(ScheduleAllocationStore::class)->save(ScheduleAllocation::forAppointment(
            $source->organization(), $source->specialistId(), $competitor->id(), $competitor->startsAt(), $competitor->endsAt(),
        ));

        self::getContainer()->get(TransferResponseConsumer::class)->consume($this->event($outbound->buttons()[0][0]['action'], $this->channel->id(), 'unavailable-transfer'));
        $this->entityManager->clear();
        $request = $this->entityManager->getRepository(TransferRequest::class)->find($response['request']['id']);
        $option = $this->entityManager->getRepository(TransferOption::class)->find($response['options'][0]['id']);
        $source = $this->entityManager->find(Appointment::class, $this->appointment->id());
        self::assertSame('OPTIONS_SENT', $request?->status()->value);
        self::assertNull($option?->selectedAt());
        self::assertNull($source?->resultStatus());
        self::assertSame(2, $this->countRows('communication_outbox'));
        self::assertSame('TRANSFER_OPTION_UNAVAILABLE', $this->entityManager->getConnection()->fetchOne('SELECT intent.type FROM communication_outbox message INNER JOIN notification_intents intent ON intent.id = message.notification_intent_id ORDER BY message.created_at DESC, message.id DESC LIMIT 1'));
    }

    public function testMovingRegularOccurrencePreservesItsSchedule(): void
    {
        $occurrenceDate = new \DateTimeImmutable('+3 days', new \DateTimeZone('UTC'));
        $schedule = RegularSchedule::create($this->administrator->organization(), $this->specialist, $this->patient, $this->appointmentService(), $occurrenceDate, null);
        $day = RegularScheduleDay::create($schedule, (int) $occurrenceDate->format('N'), '11:00', 60, $occurrenceDate);
        $regular = Appointment::fromRegularSchedule(
            $schedule,
            $day,
            $occurrenceDate,
            new \DateTimeImmutable($occurrenceDate->format('Y-m-d').' 11:00', new \DateTimeZone('Europe/Moscow')),
        );
        foreach ([$schedule, $day, $regular] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        self::getContainer()->get(ScheduleAllocationStore::class)->save(ScheduleAllocation::forAppointment(
            $this->administrator->organization(), $this->specialist->id(), $regular->id(), $regular->startsAt(), $regular->endsAt(),
        ));
        $this->appointment = $regular;
        $this->sourceStart = $regular->startsAt()->setTimezone(new \DateTimeZone('Europe/Moscow'));

        $response = $this->offer([$this->sourceStart->modify('+1 day')]);
        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([], ['createdAt' => 'DESC']);
        self::assertInstanceOf(OutboundMessage::class, $outbound);
        self::getContainer()->get(TransferResponseConsumer::class)->consume($this->event($outbound->buttons()[0][0]['action'], $this->channel->id(), 'regular-transfer'));

        $this->entityManager->clear();
        $request = $this->entityManager->getRepository(TransferRequest::class)->find($response['request']['id']);
        $source = $this->entityManager->find(Appointment::class, $regular->id());
        $newAppointment = $this->entityManager->find(Appointment::class, $request?->newAppointmentId());
        $storedSchedule = $this->entityManager->find(RegularSchedule::class, $schedule->id());
        self::assertTrue($source?->regularScheduleId()?->equals($schedule->id()));
        self::assertNull($newAppointment?->regularScheduleId());
        self::assertNull($storedSchedule?->inactiveFrom());
        self::assertTrue($storedSchedule?->isActiveOn($occurrenceDate->modify('+7 days')) ?? false);
    }

    public function testWrongChannelCannotSelectAndClientCanDeclineAllOptions(): void
    {
        $response = $this->offer([$this->sourceStart->modify('+1 day')]);
        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([]);
        self::assertInstanceOf(OutboundMessage::class, $outbound);
        $patient = $this->entityManager->find(Client::class, $this->patient->id());
        self::assertInstanceOf(Client::class, $patient);
        $other = ChannelConnection::create($patient, null, 'MAX', '987654321');
        $other->activate();
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $consumer = self::getContainer()->get(TransferResponseConsumer::class);
        $consumer->consume($this->event($outbound->buttons()[0][0]['action'], $other->id(), 'wrong-transfer-channel'));
        $consumer->consume($this->event($outbound->buttons()[1][0]['action'], $this->channel->id(), 'decline-transfer'));
        $this->entityManager->clear();
        $request = $this->entityManager->getRepository(TransferRequest::class)->find($response['request']['id']);
        self::assertSame('DECLINED', $request?->status()->value);
        self::assertNull($this->entityManager->find(Appointment::class, $this->appointment->id())?->resultStatus());
        self::assertSame(1, $this->countRows('appointments'));
        self::assertSame(1, $this->countRows('schedule_allocations'));
    }

    public function testConfirmationTransferActionCreatesAwaitingRequest(): void
    {
        $this->browser->jsonRequest('POST', '/api/appointments/'.$this->appointment->id()->toRfc4122().'/confirmation', [], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseStatusCodeSame(201);
        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([]);
        self::assertInstanceOf(OutboundMessage::class, $outbound);
        self::getContainer()->get(ConfirmationResponseConsumer::class)->consume($this->event($outbound->buttons()[2][0]['action'], $this->channel->id(), 'request-transfer'));

        $this->entityManager->clear();
        $request = $this->entityManager->getRepository(TransferRequest::class)->findOneBy([]);
        self::assertSame('AWAITING_OPTIONS', $request?->status()->value);
        self::assertSame('CANNOT_ATTEND', $this->entityManager->getConnection()->fetchOne('SELECT status FROM appointment_confirmation_requests'));
        self::assertContains('TRANSFER_REQUESTED', $this->sourceEventTypes());
    }

    public function testChangingAppointmentResultCancelsActiveTransfer(): void
    {
        $response = $this->offer([$this->sourceStart->modify('+1 day')]);

        $this->browser->jsonRequest('PUT', '/api/appointments/'.$this->appointment->id()->toRfc4122().'/result', [
            'status' => 'CANCELLED_BY_CLIENT',
            'respectfulReason' => false,
            'comment' => null,
            'createFreeWindow' => false,
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $request = $this->entityManager->getRepository(TransferRequest::class)->find($response['request']['id']);
        self::assertSame('CANCELLED', $request?->status()->value);
        self::assertSame(['TRANSFER_OPTIONS_SENT', 'TRANSFER_CANCELLED', 'RESULT_CHANGED'], $this->sourceEventTypes());
    }

    public function testAdministratorCanCancelActiveTransfer(): void
    {
        $response = $this->offer([$this->sourceStart->modify('+1 day')]);

        $this->browser->request('DELETE', '/api/transfer-requests/'.$response['request']['id'], server: ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        self::assertResponseStatusCodeSame(204);
        $this->entityManager->clear();
        $request = $this->entityManager->getRepository(TransferRequest::class)->find($response['request']['id']);
        self::assertSame('CANCELLED', $request?->status()->value);
        self::assertNull($this->entityManager->find(Appointment::class, $this->appointment->id())?->resultStatus());
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM schedule_allocations WHERE released_at IS NULL'));
    }

    public function testForeignTenantCannotReadOrCancelTransfer(): void
    {
        $response = $this->offer([$this->sourceStart->modify('+1 day')]);
        $foreign = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Foreign transfer', 'foreign-transfer-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'UTC');
        $this->browser->loginUser($foreign);
        $this->browser->request('GET', '/api/appointments/'.$this->appointment->id()->toRfc4122().'/transfer');
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/api/auth/csrf');
        $foreignCsrf = $this->json()['mutationToken'];
        $this->browser->request('DELETE', '/api/transfer-requests/'.$response['request']['id'], server: ['HTTP_X_CSRF_TOKEN' => $foreignCsrf]);
        self::assertResponseStatusCodeSame(404);
    }

    /** @param list<\DateTimeImmutable> $starts */
    private function offer(array $starts): array
    {
        $this->browser->jsonRequest('POST', '/api/appointments/'.$this->appointment->id()->toRfc4122().'/transfer', [
            'options' => array_map(static fn (\DateTimeImmutable $start): array => ['date' => $start->format('Y-m-d'), 'startTime' => $start->format('H:i')], $starts),
            'acceptedWarnings' => [],
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        return $this->json();
    }

    private function event(string $action, Ulid $channelId, string $id): NormalizedWebhookEvent
    {
        return NormalizedWebhookEvent::create($this->administrator->organization(), $channelId, CommunicationProvider::MAX, $id, ['kind' => 'BUTTON', 'action' => $action]);
    }

    private function appointmentService(): Service
    {
        $service = $this->entityManager->find(Service::class, $this->appointment->serviceId());

        return $service instanceof Service ? $service : throw new \LogicException('Appointment service is missing.');
    }

    private function countRows(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    /** @return list<string> */
    private function sourceEventTypes(): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn('SELECT event_type FROM appointment_events WHERE appointment_id = ? ORDER BY occurred_at, id', [$this->appointment->id()->toRfc4122()]);
    }

    private function json(): array
    {
        return json_decode((string) $this->browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
