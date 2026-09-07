<?php

declare(strict_types=1);

use App\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$mode = $argv[1] ?? '';
$run = $argv[2] ?? '';
if (!in_array($mode, ['create', 'remove'], true) || !preg_match('/^[a-f0-9-]{8,40}$/D', $run)) {
    throw new RuntimeException('Usage: workforce.php create|remove <unique-run-id>');
}
if ('dev' !== ($_SERVER['APP_ENV'] ?? getenv('APP_ENV'))) {
    throw new RuntimeException('Local development fixtures only.');
}
$kernel = new Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();
$connection = $em->getConnection();
$hasher = new Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher();

foreach (['desktop', 'mobile', 'browser'] as $device) {
    $email = "workforce-e2e-$run-$device@example.test";
    $name = "Disposable workforce E2E $run $device";
    if ('create' === $mode) {
        $organization = App\Module\Organization\Domain\Model\Organization::create($name, 'Europe/Moscow');
        $admin = App\Module\Identity\Domain\Model\AdministratorAccount::create($organization, $email, $hasher->hash('Workforce-E2E-test-only'));
        $em->wrapInTransaction(static function () use ($em, $organization, $admin): void {
            $em->persist($organization);
            $em->persist($admin);
        });
    } else {
        // Match both fixture email and organization marker; never touch another tenant.
        $id = $connection->fetchOne('SELECT o.id FROM organizations o JOIN administrator_accounts a ON a.organization_id = o.id WHERE a.normalized_email = ? AND o.name = ?', [$email, $name]);
        if (false === $id) { continue; }
        $connection->transactional(static function () use ($connection, $id): void {
            $connection->executeStatement('DELETE FROM additional_working_days WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM specialists WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM administrator_accounts WHERE organization_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$id]);
        });
    }
}
fwrite(STDOUT, "Workforce fixtures $mode: $run\n");
$kernel->shutdown();
