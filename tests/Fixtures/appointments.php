<?php

declare(strict_types=1);

use App\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$mode = $argv[1] ?? '';
$run = $argv[2] ?? '';
if (!in_array($mode, ['create', 'remove'], true) || !preg_match('/^[a-f0-9-]{8,40}$/D', $run)) {
    throw new RuntimeException('Usage: appointments.php create|remove <unique-run-id>');
}
if ('dev' !== ($_SERVER['APP_ENV'] ?? getenv('APP_ENV'))) {
    throw new RuntimeException('Local development fixtures only.');
}

$kernel = new Kernel('dev', true);
$kernel->boot();
$entityManager = $kernel->getContainer()->get('doctrine')->getManager();
$connection = $entityManager->getConnection();
$hasher = new Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher();

foreach (['desktop', 'mobile', 'browser'] as $device) {
    $email = "appointments-e2e-$run-$device@example.test";
    $name = "Disposable appointments E2E $run $device";
    if ('create' === $mode) {
        $organization = App\Module\Organization\Domain\Model\Organization::create($name, 'Europe/Moscow');
        $administrator = App\Module\Identity\Domain\Model\AdministratorAccount::create($organization, $email, $hasher->hash('Appointments-E2E-test-only'));
        $specialist = new App\Module\Workforce\Domain\Model\Specialist(new Symfony\Component\Uid\Ulid(), $organization, 'Юлия Иванова', 'Логопед');
        $week = App\Module\Workforce\Domain\Model\SpecialistWeeklyHours::emptyWeek();
        $week[0] = ['weekday' => 1, 'enabled' => true, 'work' => ['start' => '09:00', 'end' => '18:00'], 'lunch' => ['start' => '13:00', 'end' => '14:00']];
        $specialist->setWeeklyHours($week);
        $client = App\Module\Clients\Domain\Model\Client::create($organization, 'Петя Сидоров', 'CHILD', null, null);
        $service = App\Module\Catalog\Domain\Model\Service::create($organization, 'Логопедическое занятие', 45, 30, 60);
        $entityManager->wrapInTransaction(static function () use ($entityManager, $organization, $administrator, $specialist, $client, $service): void {
            $entityManager->persist($organization);
            $entityManager->persist($administrator);
            $entityManager->persist($specialist);
            $entityManager->persist($client);
            $entityManager->persist($service);
            $entityManager->flush();
            $channel = App\Module\Clients\Domain\Model\ChannelConnection::create($client, null, 'MAX', 'e2e-'.$client->id()->toRfc4122());
            $channel->activate();
            $entityManager->persist($channel);
            $entityManager->flush();
            $client->selectPrimaryChannel($channel);
        });
    } else {
        $id = $connection->fetchOne('SELECT o.id FROM organizations o JOIN administrator_accounts a ON a.organization_id = o.id WHERE a.normalized_email = ? AND o.name = ?', [$email, $name]);
        if (false === $id) {
            continue;
        }
        $connection->transactional(static function () use ($connection, $id): void {
            $connection->executeStatement('DELETE FROM waiting_list_availability WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM waiting_list_entries WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM free_windows WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM transfer_options WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM transfer_requests WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM scheduling_settings WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM appointment_confirmation_actions WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM appointment_confirmation_requests WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM communication_outbox WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM notification_intents WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM confirmation_settings WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM appointment_events WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM schedule_allocations WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM appointments WHERE organization_id = ?', [$id]);
            $connection->executeStatement('UPDATE clients SET primary_channel_id = NULL WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM channel_connections WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM clients WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM services WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM specialists WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM administrator_accounts WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$id]);
        });
    }
}

fwrite(STDOUT, "Appointment fixtures $mode: $run\n");
$kernel->shutdown();
