<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\Infrastructure\Persistence;

use App\Module\Organization\Domain\Model\Organization;
use App\Module\Workforce\Domain\Model\Specialist;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

#[Group('database-concurrency')]
final class ScheduleAllocationConcurrencyTest extends KernelTestCase
{
    public function testConcurrentInsertsHaveOneWinner(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the concurrency check.');
        }

        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $organization = Organization::create('Allocation concurrency '.new Ulid(), 'UTC');
        $specialist = new Specialist(new Ulid(), $organization, 'Concurrency', 'Test');
        $entityManager->persist($organization);
        $entityManager->persist($specialist);
        $entityManager->flush();

        $connection = $entityManager->getConnection();
        $params = $connection->getParams();
        $organizationId = $organization->id()->toRfc4122();
        $specialistId = $specialist->id()->toRfc4122();
        $marker = sys_get_temp_dir().'/vovremya-allocation-'.$organizationId;
        @unlink($marker);
        $connection->close();

        try {
            $first = pcntl_fork();
            if (0 === $first) {
                $pdo = self::pdo($params);
                $pdo->beginTransaction();
                self::insert($pdo, $organizationId, $specialistId, new Ulid());
                file_put_contents($marker, 'ready');
                usleep(500_000);
                $pdo->commit();
                exit(0);
            }
            self::assertGreaterThan(0, $first);

            $second = pcntl_fork();
            if (0 === $second) {
                $deadline = microtime(true) + 5;
                while (!is_file($marker) && microtime(true) < $deadline) {
                    usleep(10_000);
                }
                if (!is_file($marker)) {
                    exit(4);
                }

                $pdo = self::pdo($params);
                try {
                    $pdo->beginTransaction();
                    self::insert($pdo, $organizationId, $specialistId, new Ulid());
                    $pdo->commit();
                    exit(3);
                } catch (\PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    exit('23P01' === $exception->getCode() ? 0 : 5);
                }
            }
            self::assertGreaterThan(0, $second);

            pcntl_waitpid($first, $firstStatus);
            pcntl_waitpid($second, $secondStatus);
            self::assertSame(0, pcntl_wexitstatus($firstStatus));
            self::assertSame(0, pcntl_wexitstatus($secondStatus));
        } finally {
            @unlink($marker);
            $connection->executeStatement('DELETE FROM schedule_allocations WHERE organization_id = ?', [$organizationId]);
            $connection->executeStatement('DELETE FROM specialists WHERE organization_id = ?', [$organizationId]);
            $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$organizationId]);
        }
    }

    /** @param array<string, mixed> $params */
    private static function pdo(array $params): \PDO
    {
        return new \PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $params['host'], $params['port'], $params['dbname']),
            (string) $params['user'],
            (string) $params['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    private static function insert(\PDO $pdo, string $organizationId, string $specialistId, Ulid $sourceId): void
    {
        $statement = $pdo->prepare(<<<'SQL'
            INSERT INTO schedule_allocations
                (id, organization_id, specialist_id, source_id, allocation_type, starts_at, ends_at, created_at)
            VALUES
                (:id, :organization, :specialist, :source, 'APPOINTMENT', '2026-09-07T10:00:00+00:00', '2026-09-07T11:00:00+00:00', NOW())
            SQL);
        $statement->execute([
            'id' => (new Ulid())->toRfc4122(),
            'organization' => $organizationId,
            'specialist' => $specialistId,
            'source' => $sourceId->toRfc4122(),
        ]);
    }
}
