<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\Infrastructure\Persistence;

use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Organization\Domain\Model\Organization;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;

#[Group('database-concurrency')]
final class WebhookInboxConcurrencyTest extends WebTestCase
{
    public function testConcurrentDuplicatesAreBothAcknowledgedAndCreateOneInboxRow(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the concurrency check.');
        }

        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $organization = Organization::create('Webhook concurrency '.new Ulid(), 'UTC');
        $entityManager->persist($organization);
        $entityManager->flush();
        $client = Client::create($organization, 'Webhook concurrency recipient', 'ADULT', null, null);
        $entityManager->persist($client);
        $entityManager->flush();
        $channel = ChannelConnection::create($client, null, 'MAX', '123456789');
        $channel->activate();
        $channel->configureWebhookSecret('max-test-secret');
        $entityManager->persist($channel);
        $entityManager->flush();

        $connection = $entityManager->getConnection();
        $organizationId = $organization->id()->toRfc4122();
        $body = '{"update_type":"message_created","update_id":"concurrent-update","user":{"user_id":1}}';
        $base = sys_get_temp_dir().'/vovremya-webhook-'.$organizationId;
        $readyFiles = [$base.'-ready-1', $base.'-ready-2'];
        $resultFiles = [$base.'-result-1', $base.'-result-2'];
        $goFile = $base.'-go';
        foreach ([...$readyFiles, ...$resultFiles, $goFile] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $connection->close();

        try {
            $children = [];
            foreach ([0, 1] as $index) {
                $child = pcntl_fork();
                if (0 === $child) {
                    self::runWebhook($channel->webhookRoutingKey(), $body, $readyFiles[$index], $goFile, $resultFiles[$index]);
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

            $responses = array_map(
                static fn (string $file): array => json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR),
                $resultFiles,
            );
            $duplicates = array_values(array_unique(array_column($responses, 'duplicate')));
            sort($duplicates);
            self::assertSame([false, true], $duplicates);
            self::assertSame(1, (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM communication_webhook_inbox WHERE organization_id = ? AND provider = ? AND external_event_id = ?',
                [$organizationId, 'MAX', 'concurrent-update'],
            ));
        } finally {
            foreach ([...$readyFiles, ...$resultFiles, $goFile] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            $connection->executeStatement('DELETE FROM communication_webhook_inbox WHERE organization_id = ?', [$organizationId]);
            $connection->executeStatement('DELETE FROM communication_normalized_events WHERE organization_id = ?', [$organizationId]);
            $connection->executeStatement('DELETE FROM clients WHERE organization_id = ?', [$organizationId]);
            $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$organizationId]);
        }
    }

    private static function runWebhook(string $routingKey, string $body, string $readyFile, string $goFile, string $resultFile): never
    {
        self::ensureKernelShutdown();
        $client = self::createClient();
        touch($readyFile);
        $deadline = microtime(true) + 10;
        while (!is_file($goFile) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (!is_file($goFile)) {
            exit(2);
        }

        $client->request(
            'POST',
            '/webhooks/communications/max/'.$routingKey,
            server: [
                'HTTP_X_MAX_BOT_API_SECRET' => 'max-test-secret',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $body,
        );
        $response = $client->getResponse();
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($resultFile, json_encode([
            'status' => $response->getStatusCode(),
            'duplicate' => $data['duplicate'] ?? null,
        ], JSON_THROW_ON_ERROR));

        exit(200 === $response->getStatusCode() ? 0 : 3);
    }
}
