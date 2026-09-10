<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\Application;

use App\Module\Catalog\Domain\Model\Service;
use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Communications\Application\ConfirmationSettingsService;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Organization\Application\OrganizationContext;
use App\Module\Scheduling\Application\ConfirmationAutomation;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentConfirmationRequest;
use App\Module\Workforce\Domain\Model\Specialist;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class ConfirmationAutomationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private \App\Module\Identity\Domain\Model\AdministratorAccount $administrator;
    private ConfirmationAutomation $automation;
    private OrganizationContext $organizationContext;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Automation', 'automation-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'Europe/Moscow');
        $this->automation = self::getContainer()->get(ConfirmationAutomation::class);
        $this->organizationContext = self::getContainer()->get(OrganizationContext::class);
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testRequestCutoffReminderAndRepeatedRunsAreIdempotent(): void
    {
        $this->appointment('2026-09-12 15:00:00', true);

        $this->process('2026-09-11 13:59:00');
        self::assertSame(0, $this->countRows('appointment_confirmation_requests'));
        $this->process('2026-09-11 14:00:00');
        $this->process('2026-09-11 14:01:00');
        self::assertSame(1, $this->countRows('appointment_confirmation_requests'));
        self::assertSame(1, $this->countRows('communication_outbox'));

        $this->process('2026-09-11 16:00:00');
        $this->entityManager->clear();
        $request = $this->entityManager->getRepository(AppointmentConfirmationRequest::class)->findOneBy([]);
        self::assertSame('NO_RESPONSE', $request?->status()->value);

        $this->process('2026-09-12 12:59:00');
        self::assertSame(1, $this->countRows('communication_outbox'));
        $this->process('2026-09-12 13:00:00');
        $this->process('2026-09-12 13:01:00');
        self::assertSame(2, $this->countRows('communication_outbox'));
        self::assertSame(4, $this->countRows('appointment_confirmation_actions'));
        $buttons = $this->entityManager->getConnection()->fetchOne("SELECT outbox.buttons FROM communication_outbox outbox INNER JOIN notification_intents intent ON intent.id = outbox.notification_intent_id WHERE intent.type = 'APPOINTMENT_REMINDER'");
        self::assertCount(2, json_decode((string) $buttons, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testQuietHoursDelayRequestAndMissingChannelIsRetriedWithoutPartialState(): void
    {
        $this->appointment('2026-09-12 15:00:00', true);
        $this->appointment('2026-09-12 16:30:00', false);
        $settingsService = self::getContainer()->get(ConfirmationSettingsService::class);
        $this->organizationContext->runWith($this->administrator->organizationId(), fn () => $settingsService->change($this->administrator, [
            'requestTime' => '14:00',
            'noResponseTime' => '16:00',
            'reminderEnabled' => true,
            'reminderLeadMinutes' => 120,
            'reminderNotBefore' => '07:00',
            'quietHoursStart' => '13:00',
            'quietHoursEnd' => '15:00',
        ]));

        $this->process('2026-09-11 14:30:00');
        self::assertSame(0, $this->countRows('appointment_confirmation_requests'));
        $this->process('2026-09-11 15:00:00');
        self::assertSame(1, $this->countRows('appointment_confirmation_requests'));
        self::assertSame(1, $this->countRows('communication_outbox'));
    }

    private function appointment(string $localStart, bool $withChannel): Appointment
    {
        $specialist = new Specialist(new Ulid(), $this->administrator->organization(), 'Юлия '.new Ulid(), 'Логопед');
        $client = Client::create($this->administrator->organization(), 'Клиент '.new Ulid(), 'ADULT', null, null);
        $service = Service::create($this->administrator->organization(), 'Занятие', 45, null, null);
        $appointment = Appointment::create(
            $this->administrator->organization(), $specialist->id(), $client->id(), $service->id(),
            $service->name(), 45, null, null, 45,
            new \DateTimeImmutable($localStart, new \DateTimeZone('Europe/Moscow')),
        );
        foreach ([$specialist, $client, $service, $appointment] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        if ($withChannel) {
            $channel = ChannelConnection::create($client, null, 'MAX', (string) random_int(100000000, 999999999));
            $channel->activate();
            $this->entityManager->persist($channel);
            $this->entityManager->flush();
            $client->selectPrimaryChannel($channel);
            $this->entityManager->flush();
        }

        return $appointment;
    }

    private function process(string $localNow): void
    {
        $this->organizationContext->runWith(
            $this->administrator->organizationId(),
            fn () => $this->automation->process(
                $this->administrator->organization(),
                new \DateTimeImmutable($localNow, new \DateTimeZone('Europe/Moscow')),
            ),
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }
}
