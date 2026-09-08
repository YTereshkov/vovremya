<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\Infrastructure\Persistence;

use App\Module\Communications\Application\CommunicationStore;
use App\Module\Communications\Application\NotificationOutbox;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Organization\Application\OrganizationContext;
use App\Module\Organization\Domain\Model\Organization;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

#[Group('database-concurrency')]
final class OutboundClaimConcurrencyTest extends KernelTestCase
{
    public function testOnlyOneWorkerClaimsOutboundMessage(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the concurrency check.');
        }

        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $organization = Organization::create('Outbound concurrency '.new Ulid(), 'UTC');
        $entityManager->persist($organization);
        $entityManager->flush();
        $context = self::getContainer()->get(OrganizationContext::class);
        $store = self::getContainer()->get(CommunicationStore::class);
        $outbox = new NotificationOutbox($entityManager, $store, $context);
        $message = $context->runWith($organization->id(), fn () => $outbox->queue(
            'CONCURRENCY_TEST',
            null,
            CommunicationProvider::MAX,
            '+79990000000',
            'Concurrency test',
            dedupeKey: 'claim-concurrency',
        ));

        $connection = $entityManager->getConnection();
        $organizationId = $organization->id()->toRfc4122();
        $messageId = $message->id()->toRfc4122();
        $base = sys_get_temp_dir().'/vovremya-outbound-'.$messageId;
        $readyFiles = [$base.'-ready-1', $base.'-ready-2'];
        $resultFiles = [$base.'-result-1', $base.'-result-2'];
        $goFile = $base.'-go';
        self::removeFiles([...$readyFiles, ...$resultFiles, $goFile]);
        $connection->close();

        try {
            $children = [];
            foreach ([0, 1] as $index) {
                $child = pcntl_fork();
                if (0 === $child) {
                    self::claim($organizationId, $messageId, $readyFiles[$index], $goFile, $resultFiles[$index]);
                }
                self::assertGreaterThan(0, $child);
                $children[] = $child;
            }

            $deadline = microtime(true) + 10;
            while ((!is_file($readyFiles[0]) || !is_file($readyFiles[1])) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFileExists($readyFiles[0]);
            self::assertFileExists($readyFiles[1]);
            touch($goFile);

            foreach ($children as $child) {
                pcntl_waitpid($child, $status);
                self::assertSame(0, pcntl_wexitstatus($status));
            }

            $results = array_map(static fn (string $file): int => (int) file_get_contents($file), $resultFiles);
            sort($results);
            self::assertSame([0, 1], $results);
        } finally {
            self::removeFiles([...$readyFiles, ...$resultFiles, $goFile]);
            $connection->executeStatement('DELETE FROM communication_outbox WHERE organization_id = ?', [$organizationId]);
            $connection->executeStatement('DELETE FROM notification_intents WHERE organization_id = ?', [$organizationId]);
            $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$organizationId]);
        }
    }

    private static function claim(string $organizationId, string $messageId, string $readyFile, string $goFile, string $resultFile): never
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $context = self::getContainer()->get(OrganizationContext::class);
        $store = self::getContainer()->get(CommunicationStore::class);
        touch($readyFile);
        $deadline = microtime(true) + 10;
        while (!is_file($goFile) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (!is_file($goFile)) {
            exit(2);
        }

        $claimed = $context->runWith(
            Ulid::fromString($organizationId),
            fn () => $store->claimOutbound(Ulid::fromString($messageId)),
        );
        file_put_contents($resultFile, null === $claimed ? '0' : '1');

        exit(0);
    }

    /** @param list<string> $files */
    private static function removeFiles(array $files): void
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
