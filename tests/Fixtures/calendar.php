<?php

declare(strict_types=1);

use App\Kernel;
use App\Module\Catalog\Domain\Model\Service;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Domain\Model\Organization;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use App\Module\Workforce\Domain\Model\Specialist;
use App\Module\Workforce\Domain\Model\SpecialistWeeklyHours;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;
use Symfony\Component\Uid\Ulid;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$mode = $argv[1] ?? '';
$run = $argv[2] ?? '';
if (!in_array($mode, ['create', 'remove'], true) || !preg_match('/^[a-f0-9-]{8,40}$/D', $run)) {
    throw new RuntimeException('Usage: calendar.php create|remove <unique-run-id>');
}
if ('dev' !== ($_SERVER['APP_ENV'] ?? getenv('APP_ENV'))) {
    throw new RuntimeException('Local development fixtures only.');
}

$kernel = new Kernel('dev', true);
$kernel->boot();
$entityManager = $kernel->getContainer()->get('doctrine')->getManager();
$connection = $entityManager->getConnection();
$hasher = new NativePasswordHasher();
$timezone = new DateTimeZone('Europe/Moscow');
$today = new DateTimeImmutable('today', $timezone);

foreach (['desktop', 'mobile'] as $device) {
    $email = "calendar-e2e-$run-$device@example.test";
    $name = "Disposable calendar E2E $run $device";
    if ('create' === $mode) {
        $organization = Organization::create($name, $timezone->getName());
        $administrator = AdministratorAccount::create($organization, $email, $hasher->hash('Calendar-E2E-test-only'));
        $firstSpecialist = new Specialist(new Ulid(), $organization, 'Юлия Иванова', 'Логопед');
        $week = SpecialistWeeklyHours::emptyWeek();
        foreach ($week as $index => $day) {
            $week[$index] = ['weekday' => $day['weekday'], 'enabled' => true, 'work' => ['start' => '08:00', 'end' => '20:00'], 'lunch' => null];
        }
        $firstSpecialist->setWeeklyHours($week);
        $specialists = [$firstSpecialist];
        if ('desktop' === $device) {
            $secondSpecialist = new Specialist(new Ulid(), $organization, 'Анна Петрова', 'Психолог');
            $secondSpecialist->setWeeklyHours($week);
            $specialists[] = $secondSpecialist;
        }
        $client = Client::create($organization, 'Петя Сидоров', 'CHILD', null, null);
        $service = Service::create($organization, 'Логопедическое занятие', 45, 30, 60);
        $appointments = [
            Appointment::create($organization, $firstSpecialist->id(), $client->id(), $service->id(), $service->name(), 45, 30, 60, 45, $today->setTime(9, 0)),
            Appointment::create($organization, $firstSpecialist->id(), $client->id(), $service->id(), $service->name(), 45, 30, 60, 45, $today->modify('+2 days')->setTime(11, 30)),
        ];
        if (isset($secondSpecialist)) {
            $appointments[] = Appointment::create($organization, $secondSpecialist->id(), $client->id(), $service->id(), $service->name(), 45, 30, 60, 45, $today->setTime(10, 30));
        }

        $entityManager->wrapInTransaction(static function () use ($entityManager, $organization, $administrator, $specialists, $client, $service, $appointments): void {
            foreach ([$organization, $administrator, ...$specialists, $client, $service, ...$appointments] as $entity) {
                $entityManager->persist($entity);
            }
            foreach ($appointments as $appointment) {
                $entityManager->persist(ScheduleAllocation::forAppointment($organization, $appointment->specialistId(), $appointment->id(), $appointment->startsAt(), $appointment->endsAt()));
            }
        });
        unset($secondSpecialist);
    } else {
        $id = $connection->fetchOne('SELECT o.id FROM organizations o JOIN administrator_accounts a ON a.organization_id = o.id WHERE a.normalized_email = ? AND o.name = ?', [$email, $name]);
        if (false === $id) {
            continue;
        }
        $connection->transactional(static function () use ($connection, $id): void {
            $connection->executeStatement('DELETE FROM appointment_events WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM schedule_allocations WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM appointments WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM schedule_generation_issues WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM regular_schedule_days WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM regular_schedules WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM clients WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM services WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM specialists WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM administrator_accounts WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$id]);
        });
    }
}

fwrite(STDOUT, "Calendar fixtures $mode: $run\n");
$kernel->shutdown();
