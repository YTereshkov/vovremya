<?php

declare(strict_types=1);

use App\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$mode = $argv[1] ?? '';
$run = $argv[2] ?? '';
if (!in_array($mode, ['create', 'remove'], true) || !preg_match('/^[a-f0-9-]{8,40}$/D', $run)) {
    throw new RuntimeException('Usage: catalog_clients.php create|remove <unique-run-id>');
}
if ('dev' !== ($_SERVER['APP_ENV'] ?? getenv('APP_ENV'))) {
    throw new RuntimeException('Local development fixtures only.');
}

$kernel = new Kernel('dev', true);
$kernel->boot();
$entityManager = $kernel->getContainer()->get('doctrine')->getManager();
$connection = $entityManager->getConnection();
$hasher = new Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher();

foreach (['desktop', 'mobile'] as $device) {
    $email = "directory-e2e-$run-$device@example.test";
    $name = "Disposable directory E2E $run $device";
    if ('create' === $mode) {
        $organization = App\Module\Organization\Domain\Model\Organization::create($name, 'Europe/Moscow');
        $administrator = App\Module\Identity\Domain\Model\AdministratorAccount::create($organization, $email, $hasher->hash('Directory-E2E-test-only'));
        $entityManager->wrapInTransaction(static function () use ($entityManager, $organization, $administrator): void {
            $entityManager->persist($organization);
            $entityManager->persist($administrator);
        });
    } else {
        // Match both fixture email and organization marker; never touch another tenant.
        $id = $connection->fetchOne('SELECT o.id FROM organizations o JOIN administrator_accounts a ON a.organization_id = o.id WHERE a.normalized_email = ? AND o.name = ?', [$email, $name]);
        if (false === $id) {
            continue;
        }
        $connection->transactional(static function () use ($connection, $id): void {
            $connection->executeStatement('UPDATE clients SET primary_channel_id = NULL WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM channel_connections WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM contact_people WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM clients WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM services WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM administrator_accounts WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$id]);
        });
    }
}

fwrite(STDOUT, "Catalog/Clients fixtures $mode: $run\n");
$kernel->shutdown();
