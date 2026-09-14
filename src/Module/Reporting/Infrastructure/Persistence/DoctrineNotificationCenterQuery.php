<?php

declare(strict_types=1);

namespace App\Module\Reporting\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Reporting\Application\NotificationCenterQuery;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineNotificationCenterQuery implements NotificationCenterQuery
{
    public function __construct(private Connection $connection, private OrganizationContext $context)
    {
    }

    public function list(Ulid $administratorId): array
    {
        $organizationId = $this->context->currentId()->toRfc4122();
        $readThrough = $this->connection->fetchOne(
            'SELECT read_through FROM administrator_notification_states WHERE organization_id = :organization AND administrator_id = :administrator',
            ['organization' => $organizationId, 'administrator' => $administratorId->toRfc4122()],
        );
        $items = [];

        foreach ($this->confirmationItems($organizationId) as $row) {
            $items[] = $this->item('confirmation-no-response', $row['id'], 'Ответа нет', $row['client_name'], $row['created_at'], '/appointments/'.$row['appointment_id'], 'warning', ['startsAt' => $row['starts_at']], $readThrough);
        }
        foreach ($this->transferItems($organizationId) as $row) {
            $awaiting = 'AWAITING_OPTIONS' === $row['status'];
            $items[] = $this->item(
                $awaiting ? 'transfer-request' : 'transfer-declined',
                $row['id'],
                $awaiting ? 'Запрос переноса' : 'Клиенту не подошли варианты',
                $row['client_name'],
                $row['event_at'],
                '/appointments/'.$row['appointment_id'].'/transfer',
                $awaiting ? 'info' : 'warning',
                ['startsAt' => $row['starts_at']],
                $readThrough,
            );
        }
        foreach ($this->freeWindowItems($organizationId) as $row) {
            $items[] = $this->item('free-window', $row['id'], 'Освободилось разовое окно', $row['service_name_snapshot'], $row['created_at'], '/waiting', 'success', ['startsAt' => $row['starts_at']], $readThrough);
        }
        foreach ($this->permanentPlaceItems($organizationId) as $row) {
            $items[] = $this->item('permanent-place', $row['id'], 'Освободилось постоянное место', $row['service_name_snapshot'], $row['created_at'], '/waiting', 'success', ['slotCount' => (int) $row['slot_count']], $readThrough);
        }
        foreach ($this->absenceItems($organizationId) as $row) {
            $count = (int) $row['appointment_count'];
            $items[] = $this->item('specialist-absence', $row['id'], $count.' '.self::plural($count, 'занятие отменено', 'занятия отменены', 'занятий отменено'), 'Проверьте доставку клиентам', $row['created_at'], '/notifications/delivery/'.$row['id'], 'danger', ['messageCount' => (int) $row['message_count']], $readThrough);
        }
        $failed = $this->failedSummary($organizationId);
        if (null !== $failed) {
            $count = (int) $failed['message_count'];
            $items[] = $this->item('delivery-failed', 'failed-'.$failed['created_at'], 'Не доставлено сообщений: '.$count, 'Требуется повторная отправка или звонок', $failed['created_at'], '/notifications/delivery', 'danger', ['messageCount' => $count], $readThrough);
        }

        usort($items, static fn (array $left, array $right): int => strcmp((string) $right['createdAt'], (string) $left['createdAt']));
        $items = array_slice($items, 0, 100);

        return ['items' => $items, 'unreadCount' => count(array_filter($items, static fn (array $item): bool => true === $item['unread']))];
    }

    public function deliveryReport(?Ulid $absenceId): array
    {
        $organizationId = $this->context->currentId()->toRfc4122();
        $rows = null === $absenceId
            ? $this->failedDeliveryRows($organizationId)
            : $this->absenceDeliveryRows($organizationId, $absenceId->toRfc4122());

        return [
            'absenceId' => $absenceId?->toRfc4122(),
            'items' => array_map(static fn (array $row): array => [
                'messageId' => $row['id'],
                'recipientId' => $row['client_id'],
                'provider' => $row['provider'],
                'status' => $row['status'],
                'clientName' => $row['client_name'],
                'contactName' => $row['contact_name'],
                'phone' => $row['phone'],
                'appointmentCount' => (int) ($row['appointment_count'] ?? 0),
                'firstStartsAt' => $row['first_starts_at'],
                'createdAt' => $row['created_at'],
                'sentAt' => $row['sent_at'],
                'deliveredAt' => $row['delivered_at'],
                'readAt' => $row['read_at'],
                'lastError' => $row['last_error'],
                'businessResponse' => $row['business_response'],
            ], $rows),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function failedDeliveryRows(string $organizationId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT outbox.id, clients.id AS client_id, outbox.provider, outbox.status, outbox.created_at, outbox.sent_at,
                   outbox.delivered_at, outbox.read_at, outbox.last_error,
                   clients.name AS client_name, contacts.name AS contact_name,
                   COALESCE(contacts.phone, clients.phone) AS phone,
                   0 AS appointment_count, NULL AS first_starts_at,
                   CASE
                       WHEN confirmations.status = 'CONFIRMED' THEN 'Подтвердил'
                       WHEN confirmations.status = 'CANNOT_ATTEND' THEN 'Не сможет'
                       WHEN confirmations.status = 'NO_RESPONSE' THEN 'Не ответил'
                       WHEN transfers.status = 'COMPLETED' THEN 'Выбрал предложенное время'
                       WHEN transfers.status = 'DECLINED' THEN 'Отказался'
                       WHEN window_offers.status = 'ACCEPTED' OR place_offers.status = 'ACCEPTED' THEN 'Согласился'
                       WHEN window_offers.status = 'DECLINED' OR place_offers.status = 'DECLINED' THEN 'Отказался'
                       ELSE NULL
                   END AS business_response
            FROM communication_outbox outbox
            INNER JOIN notification_intents intent
                ON intent.organization_id = outbox.organization_id AND intent.id = outbox.notification_intent_id
            INNER JOIN channel_connections channels
                ON channels.organization_id = outbox.organization_id AND channels.id = outbox.channel_connection_id
            INNER JOIN clients
                ON clients.organization_id = channels.organization_id AND clients.id = channels.client_id
            LEFT JOIN contact_people contacts
                ON contacts.organization_id = channels.organization_id AND contacts.id = channels.contact_person_id
            LEFT JOIN appointment_confirmation_requests confirmations
                ON confirmations.organization_id = intent.organization_id
               AND confirmations.id = NULLIF(intent.payload->>'confirmationRequestId', '')::UUID
            LEFT JOIN transfer_requests transfers
                ON transfers.organization_id = intent.organization_id
               AND transfers.id = NULLIF(intent.payload->>'transferRequestId', '')::UUID
            LEFT JOIN free_window_offers window_offers
                ON window_offers.organization_id = intent.organization_id
               AND window_offers.id = NULLIF(intent.payload->>'freeWindowOfferId', '')::UUID
            LEFT JOIN permanent_place_offers place_offers
                ON place_offers.organization_id = intent.organization_id
               AND place_offers.id = NULLIF(intent.payload->>'permanentPlaceOfferId', '')::UUID
            WHERE outbox.organization_id = :organization AND outbox.status = 'FAILED'
            ORDER BY outbox.created_at DESC
            SQL, ['organization' => $organizationId]);
    }

    /** @return list<array<string, mixed>> */
    private function absenceDeliveryRows(string $organizationId, string $absenceId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            WITH affected_clients AS (
                SELECT appointments.client_id, COUNT(DISTINCT appointments.id) AS appointment_count,
                       MIN(appointments.starts_at) AS first_starts_at
                FROM appointment_events events
                INNER JOIN appointments
                    ON appointments.organization_id = events.organization_id AND appointments.id = events.appointment_id
                WHERE events.organization_id = :organization AND events.payload->>'absenceId' = :absence
                GROUP BY appointments.client_id
            )
            SELECT outbox.id, clients.id AS client_id, channels.provider,
                   CASE WHEN channels.id IS NULL THEN 'NO_CHANNEL'
                        WHEN outbox.id IS NULL THEN 'NOT_QUEUED'
                        ELSE outbox.status END AS status,
                   COALESCE(outbox.created_at, absences.created_at) AS created_at,
                   outbox.sent_at, outbox.delivered_at, outbox.read_at, outbox.last_error,
                   clients.name AS client_name, contacts.name AS contact_name,
                   COALESCE(contacts.phone, clients.phone) AS phone,
                   affected_clients.appointment_count, affected_clients.first_starts_at,
                   NULL AS business_response
            FROM affected_clients
            INNER JOIN clients ON clients.organization_id = :organization AND clients.id = affected_clients.client_id
            INNER JOIN specialist_absences absences ON absences.organization_id = clients.organization_id AND absences.id = :absence
            LEFT JOIN channel_connections channels
                ON channels.organization_id = clients.organization_id AND channels.id = clients.primary_channel_id
            LEFT JOIN contact_people contacts
                ON contacts.organization_id = channels.organization_id AND contacts.id = channels.contact_person_id
            LEFT JOIN notification_intents intent
                ON intent.organization_id = clients.organization_id AND intent.recipient_channel_id = channels.id
               AND intent.type = 'SPECIALIST_ABSENCE' AND intent.payload->>'absenceId' = :absence
            LEFT JOIN communication_outbox outbox
                ON outbox.organization_id = intent.organization_id AND outbox.notification_intent_id = intent.id
            ORDER BY clients.name, outbox.created_at DESC
            SQL, ['organization' => $organizationId, 'absence' => $absenceId]);
    }

    /** @return list<array<string, mixed>> */
    private function confirmationItems(string $organizationId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT requests.id, requests.appointment_id, requests.no_response_at AS created_at, appointments.starts_at, clients.name AS client_name
            FROM appointment_confirmation_requests requests
            INNER JOIN appointments ON appointments.organization_id = requests.organization_id AND appointments.id = requests.appointment_id
            INNER JOIN clients ON clients.organization_id = appointments.organization_id AND clients.id = appointments.client_id
            WHERE requests.organization_id = :organization AND requests.status = 'NO_RESPONSE' AND appointments.result_status IS NULL
            SQL, ['organization' => $organizationId]);
    }

    /** @return list<array<string, mixed>> */
    private function transferItems(string $organizationId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT requests.id, requests.appointment_id, requests.status, COALESCE(requests.closed_at, requests.created_at) AS event_at,
                   appointments.starts_at, clients.name AS client_name
            FROM transfer_requests requests
            INNER JOIN appointments ON appointments.organization_id = requests.organization_id AND appointments.id = requests.appointment_id
            INNER JOIN clients ON clients.organization_id = appointments.organization_id AND clients.id = appointments.client_id
            WHERE requests.organization_id = :organization AND requests.status IN ('AWAITING_OPTIONS', 'DECLINED')
            SQL, ['organization' => $organizationId]);
    }

    /** @return list<array<string, mixed>> */
    private function freeWindowItems(string $organizationId): array
    {
        return $this->connection->fetchAllAssociative("SELECT id, service_name_snapshot, starts_at, created_at FROM free_windows WHERE organization_id = :organization AND status = 'OPEN'", ['organization' => $organizationId]);
    }

    /** @return list<array<string, mixed>> */
    private function permanentPlaceItems(string $organizationId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT places.id, places.service_name_snapshot, places.created_at, COUNT(slots.id) AS slot_count
            FROM permanent_places places
            LEFT JOIN permanent_place_slots slots ON slots.organization_id = places.organization_id AND slots.permanent_place_id = places.id
            WHERE places.organization_id = :organization AND places.status = 'OPEN'
            GROUP BY places.id, places.service_name_snapshot, places.created_at
            SQL, ['organization' => $organizationId]);
    }

    /** @return list<array<string, mixed>> */
    private function absenceItems(string $organizationId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT absences.id, absences.created_at, COUNT(DISTINCT events.appointment_id) AS appointment_count,
                   COUNT(DISTINCT outbox.id) AS message_count
            FROM specialist_absences absences
            INNER JOIN appointment_events events
                ON events.organization_id = absences.organization_id AND events.payload->>'absenceId' = CAST(absences.id AS TEXT)
            LEFT JOIN notification_intents intent
                ON intent.organization_id = absences.organization_id AND intent.type = 'SPECIALIST_ABSENCE' AND intent.payload->>'absenceId' = CAST(absences.id AS TEXT)
            LEFT JOIN communication_outbox outbox
                ON outbox.organization_id = intent.organization_id AND outbox.notification_intent_id = intent.id
            WHERE absences.organization_id = :organization AND absences.notify_clients = TRUE
            GROUP BY absences.id, absences.created_at
            SQL, ['organization' => $organizationId]);
    }

    /** @return array<string, mixed>|null */
    private function failedSummary(string $organizationId): ?array
    {
        $row = $this->connection->fetchAssociative("SELECT COUNT(*) AS message_count, MAX(created_at) AS created_at FROM communication_outbox WHERE organization_id = :organization AND status = 'FAILED'", ['organization' => $organizationId]);

        return false === $row || 0 === (int) $row['message_count'] ? null : $row;
    }

    /** @param array<string, mixed> $metadata
     *  @return array<string, mixed>
     */
    private function item(string $type, string $id, string $title, string $subtitle, string $createdAt, string $href, string $severity, array $metadata, mixed $readThrough): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'subtitle' => $subtitle,
            'createdAt' => $createdAt,
            'href' => $href,
            'severity' => $severity,
            'unread' => false === $readThrough || new \DateTimeImmutable($createdAt) > new \DateTimeImmutable((string) $readThrough),
            'metadata' => $metadata,
        ];
    }

    private static function plural(int $count, string $one, string $few, string $many): string
    {
        $mod100 = $count % 100;
        $mod10 = $count % 10;
        if (1 === $mod10 && 11 !== $mod100) {
            return $one;
        }

        return 2 <= $mod10 && 4 >= $mod10 && (12 > $mod100 || 14 < $mod100) ? $few : $many;
    }
}
