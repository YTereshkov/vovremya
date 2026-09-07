<?php

declare(strict_types=1);

namespace App\Tests\Module\Workforce\UI\Http;

use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Workforce\Domain\Model\AdditionalWorkingDay;
use App\Module\Workforce\Domain\Model\Specialist;
use App\Module\Workforce\Domain\Model\SpecialistWeeklyHours;
use App\Shared\Domain\MultiTenancy\CrossOrganizationAssociation;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Ulid;

final class WorkforceControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AdministratorAccount $admin;
    private AdministratorAccount $other;
    private string $csrf;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $handler = self::getContainer()->get(CreateAdministratorHandler::class);
        $this->admin = $handler->create('Workforce A', 'workforce-a@example.test', 'test-password', 'Europe/Moscow');
        $this->other = $handler->create('Workforce B', 'workforce-b@example.test', 'test-password', 'Europe/Moscow');
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/api/auth/csrf');
        $this->csrf = $this->json()['mutationToken'];
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) { $connection->rollBack(); }
        $this->em->clear();
        parent::tearDown();
    }

    public function testCrudAndScheduleRoundtrip(): void
    {
        $created = $this->send('POST', '/api/specialists', ['name' => 'Юлия Иванова', 'specialization' => 'Логопед', 'administratorId' => $this->admin->id()->toRfc4122()]);
        self::assertResponseStatusCodeSame(201);
        $url = '/api/specialists/'.$created['id'];
        self::assertSame($this->admin->id()->toRfc4122(), $created['administratorId']);
        self::assertSame([], $created['todayIntervals']);
        $week = SpecialistWeeklyHours::emptyWeek();
        $week[0] = ['weekday' => 1, 'enabled' => true, 'work' => ['start' => '09:00', 'end' => '18:00'], 'lunch' => ['start' => '13:00', 'end' => '14:00']];
        $this->send('PUT', $url.'/weekly-hours', ['days' => $week]);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        $this->client->request('GET', $url);
        self::assertEquals($week, $this->json()['weeklyHours']);
        $day = $this->send('POST', $url.'/additional-days', ['date' => '2026-09-12', 'work' => ['start' => '10:00', 'end' => '15:00']]);
        self::assertResponseStatusCodeSame(201);
        $dayUrl = $url.'/additional-days/'.$day['additionalDays'][0]['id'];
        $this->send('PUT', $dayUrl, ['date' => '2026-09-13', 'work' => ['start' => '11:00', 'end' => '16:00']]);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        $this->client->request('GET', $url);
        self::assertSame('2026-09-13', $this->json()['additionalDays'][0]['date']);
        $this->send('PUT', $url, ['name' => 'Юлия Петрова', 'specialization' => 'Логопед', 'administratorId' => null]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['administratorId']);
        $this->send('DELETE', $dayUrl);
        self::assertResponseStatusCodeSame(204);
        $this->send('DELETE', $url);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTenantReadWriteAndAssociationIsolation(): void
    {
        $foreign = new Specialist(new Ulid(), $this->other->organization(), 'Чужой специалист', 'Психолог');
        $this->em->persist($foreign); $this->em->flush();
        $this->client->request('GET', '/api/specialists');
        self::assertSame([], $this->json());
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            $this->send($method, '/api/specialists/'.$foreign->id()->toRfc4122(), ['name' => 'Изменён', 'specialization' => 'Логопед']);
            self::assertResponseStatusCodeSame(404);
        }
        $this->send('PUT', '/api/specialists/'.$foreign->id()->toRfc4122().'/weekly-hours', ['days' => SpecialistWeeklyHours::emptyWeek()]);
        self::assertResponseStatusCodeSame(404);
        $this->send('POST', '/api/specialists', ['name' => 'Связь', 'specialization' => 'Логопед', 'administratorId' => $this->other->id()->toRfc4122()]);
        self::assertResponseStatusCodeSame(404);
        $this->send('POST', '/api/specialists', ['name' => 'Подмена', 'specialization' => 'Логопед', 'organizationId' => $this->other->organizationId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectsInvalidPayloadsAndCsrf(): void
    {
        $this->client->jsonRequest('POST', '/api/specialists', ['name' => 'Test', 'specialization' => 'Test']);
        self::assertResponseStatusCodeSame(403);
        $this->send('POST', '/api/specialists', ['name' => '', 'specialization' => 'Логопед']);
        self::assertResponseStatusCodeSame(422);
        $s = $this->send('POST', '/api/specialists', ['name' => 'Тест', 'specialization' => 'Логопед']);
        self::assertResponseStatusCodeSame(201);
        $url = '/api/specialists/'.$s['id'];
        $this->send('PUT', $url.'/weekly-hours', ['days' => []]);
        self::assertResponseStatusCodeSame(422);
        $this->send('POST', $url.'/additional-days', ['date' => '2026-02-30', 'work' => ['start' => '10:00', 'end' => '15:00']]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('GET', $url);
        self::assertSame([], $this->json()['additionalDays']);
        self::assertEquals(SpecialistWeeklyHours::emptyWeek(), $this->json()['weeklyHours']);
    }

    public function testDuplicateAdditionalDayIsConflict(): void
    {
        $s = $this->send('POST', '/api/specialists', ['name' => 'Тест', 'specialization' => 'Логопед']);
        $payload = ['date' => '2026-09-12', 'work' => ['start' => '10:00', 'end' => '15:00']];
        $this->send('POST', '/api/specialists/'.$s['id'].'/additional-days', $payload);
        self::assertResponseStatusCodeSame(201);
        $this->send('POST', '/api/specialists/'.$s['id'].'/additional-days', $payload);
        self::assertResponseStatusCodeSame(409);
    }

    public function testDomainRejectsForeignAdministrator(): void
    {
        $s = new Specialist(new Ulid(), $this->admin->organization(), 'Тест', 'Логопед');
        $this->expectException(CrossOrganizationAssociation::class);
        $s->linkAdministrator($this->other);
    }

    public function testAdministratorCannotHaveTwoSpecialistProfiles(): void
    {
        $profile = ['name' => 'Тест', 'specialization' => 'Логопед', 'administratorId' => $this->admin->id()->toRfc4122()];
        $this->send('POST', '/api/specialists', $profile);
        self::assertResponseStatusCodeSame(201);
        $this->send('POST', '/api/specialists', $profile);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAdditionalDayCannotBeAccessedThroughAnotherSpecialist(): void
    {
        $foreign = new Specialist(new Ulid(), $this->other->organization(), 'Чужой специалист', 'Психолог');
        $own = new Specialist(new Ulid(), $this->admin->organization(), 'Другой специалист', 'Логопед');
        $payload = ['date' => '2026-09-12', 'work' => ['start' => '10:00', 'end' => '15:00']];
        foreach ([$foreign, $own] as $specialist) {
            $this->em->persist($specialist);
        }
        $foreignDay = new AdditionalWorkingDay(new Ulid(), $foreign, $payload['date'], $payload['work']);
        $ownDay = new AdditionalWorkingDay(new Ulid(), $own, $payload['date'], $payload['work']);
        $this->em->persist($foreignDay);
        $this->em->persist($ownDay);
        $this->em->flush();

        $profile = $this->send('POST', '/api/specialists', ['name' => 'Мой профиль', 'specialization' => 'Логопед']);
        self::assertResponseStatusCodeSame(201);
        foreach ([$foreignDay, $ownDay] as $day) {
            $url = '/api/specialists/'.$profile['id'].'/additional-days/'.$day->id()->toRfc4122();
            $this->send('PUT', $url, $payload);
            self::assertResponseStatusCodeSame(404);
            $this->send('DELETE', $url);
            self::assertResponseStatusCodeSame(404);
        }
        $this->client->request('GET', '/api/specialists/'.$own->id()->toRfc4122());
        self::assertCount(1, $this->json()['additionalDays']);
        $this->send('POST', '/api/specialists/'.$foreign->id()->toRfc4122().'/additional-days', $payload);
        self::assertResponseStatusCodeSame(404);

        $connection = $this->em->getConnection();
        $connection->createSavepoint('foreign_specialist');
        try {
            $connection->executeStatement('UPDATE additional_working_days SET specialist_id = ? WHERE id = ?', [$foreign->id()->toRfc4122(), $ownDay->id()->toRfc4122()]);
            self::fail('Cross-tenant specialist foreign key accepted.');
        } catch (ForeignKeyConstraintViolationException) {
            self::addToAssertionCount(1);
        } finally {
            $connection->rollbackSavepoint('foreign_specialist');
        }
    }

    public function testAdditionalWorkingDayAppearsInTodayIntervals(): void
    {
        $profile = $this->send('POST', '/api/specialists', ['name' => 'Тест', 'specialization' => 'Логопед']);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));
        $work = ['start' => '10:00', 'end' => '15:00'];
        $this->send('POST', '/api/specialists/'.$profile['id'].'/additional-days', ['date' => $today->format('Y-m-d'), 'work' => $work]);
        self::assertResponseStatusCodeSame(201);
        $this->em->clear();
        $this->client->request('GET', '/api/specialists');
        self::assertEquals([$work], $this->json()[0]['todayIntervals']);
        self::assertSame($today->format('Y-m-d'), $this->json()[0]['today']);
    }

    public function testDatabaseRejectsForeignAdministratorEvenWithoutDomainGuard(): void
    {
        $s = new Specialist(new Ulid(), $this->admin->organization(), 'Тест', 'Логопед');
        $this->em->persist($s); $this->em->flush();
        $connection = $this->em->getConnection();
        $connection->createSavepoint('foreign_admin');
        try {
            $connection->executeStatement('UPDATE specialists SET administrator_id = ? WHERE id = ?', [$this->other->id()->toRfc4122(), $s->id()->toRfc4122()]);
            self::fail('Cross-tenant foreign key accepted.');
        } catch (ForeignKeyConstraintViolationException) {
            self::addToAssertionCount(1);
        } finally { $connection->rollbackSavepoint('foreign_admin'); }
    }

    private function send(string $method, string $url, array $data = []): array
    {
        $this->client->jsonRequest($method, $url, $data, ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        return 204 === $this->client->getResponse()->getStatusCode() ? [] : $this->json();
    }

    private function json(): array { return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR); }
}
