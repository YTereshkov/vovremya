<?php

declare(strict_types=1);

namespace App\Tests\Module\Clients\Infrastructure\Persistence;

use App\Module\Clients\Application\ChannelConnectionResolver;
use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Organization\Application\OrganizationContext;
use App\Module\Organization\Domain\Model\Organization;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

#[Group('database-concurrency')]
final class ChannelActivationConcurrencyTest extends KernelTestCase
{
    public function testActivationTokenCanBeConsumedOnlyOnceConcurrently(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the concurrency check.');
        }

        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $organization = Organization::create('Activation concurrency '.new Ulid(), 'UTC');
        $client = Client::create($organization, 'Activation recipient', 'ADULT', null, null);
        $channel = ChannelConnection::create($client, null, 'MAX', 'pending');
        $channel->configureWebhookSecret('test-webhook-secret');
        $token = 'activation-concurrency-token';
        $channel->startActivation($token, new DateTimeImmutable('+5 minutes', new DateTimeZone('UTC')));
        $entityManager->persist($organization);
        $entityManager->persist($client);
        $entityManager->persist($channel);
        $entityManager->flush();

        $connection = $entityManager->getConnection();
        $organizationId = $organization->id()->toRfc4122();
        $channelId = $channel->id()->toRfc4122();
        $base = sys_get_temp_dir().'/vovremya-activation-'.$channelId;
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
                    self::activate(
                        $organizationId,
                        $channelId,
                        $token,
                        'max-user-'.($index + 1),
                        $readyFiles[$index],
                        $goFile,
                        $resultFiles[$index],
                    );
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
            $connection->executeStatement('DELETE FROM channel_connections WHERE organization_id = ?', [$organizationId]);
            $connection->executeStatement('DELETE FROM clients WHERE organization_id = ?', [$organizationId]);
            $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$organizationId]);
        }
    }

    private static function activate(
        string $organizationId,
        string $channelId,
        string $token,
        string $address,
        string $readyFile,
        string $goFile,
        string $resultFile,
    ): never {
        self::ensureKernelShutdown();
        self::bootKernel();
        $context = self::getContainer()->get(OrganizationContext::class);
        $resolver = self::getContainer()->get(ChannelConnectionResolver::class);
        touch($readyFile);
        $deadline = microtime(true) + 10;
        while (!is_file($goFile) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (!is_file($goFile)) {
            exit(2);
        }

        $activated = $context->runWith(
            Ulid::fromString($organizationId),
            fn () => $resolver->activatePendingChannelForTenant(
                Ulid::fromString($channelId),
                $token,
                $address,
            ),
        );
        file_put_contents($resultFile, null === $activated ? '0' : '1');

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
