<?php

declare(strict_types=1);

namespace CMS\Modules\Notification\Repository;

use CMS\Core\Database\Connection;
use CMS\Modules\Notification\Entity\Notification;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of notifications and the email outbox.
 */
final class PdoNotificationRepository implements NotificationRepositoryInterface
{
    private const COLUMNS = 'id, site_id, user_id, event, title, body, content_id, read_at, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(int $siteId, int $userId, array $attributes): Notification
    {
        $now = $this->now();

        $this->connection->affect(
            'INSERT INTO notifications (site_id, user_id, channel, event, title, body, content_id, read_at, created_at) '
            . 'VALUES (:site_id, :user_id, :channel, :event, :title, :body, :content_id, NULL, :created_at)',
            [
                'site_id' => $siteId,
                'user_id' => $userId,
                'channel' => 'internal',
                'event' => (string) $attributes['event'],
                'title' => (string) $attributes['title'],
                'body' => (string) $attributes['body'],
                'content_id' => $attributes['content_id'] ?? null,
                'created_at' => $now,
            ]
        );

        $id = (int) $this->connection->lastInsertId();

        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM notifications WHERE id = :id LIMIT 1',
            ['id' => $id]
        );

        if ($row === null) {
            throw new \RuntimeException('Notification was inserted but could not be read back.');
        }

        return Notification::fromRow($row);
    }

    public function listForUser(int $siteId, int $userId, bool $unreadOnly, int $limit): array
    {
        $clauses = ['site_id = :site_id', 'user_id = :user_id'];

        if ($unreadOnly) {
            $clauses[] = 'read_at IS NULL';
        }

        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . ' FROM notifications WHERE ' . implode(' AND ', $clauses)
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit),
            ['site_id' => $siteId, 'user_id' => $userId]
        );

        return array_map(static fn (array $row): Notification => Notification::fromRow($row), $rows);
    }

    public function unreadCount(int $siteId, int $userId): int
    {
        $row = $this->connection->selectOne(
            'SELECT COUNT(*) AS total FROM notifications '
            . 'WHERE site_id = :site_id AND user_id = :user_id AND read_at IS NULL',
            ['site_id' => $siteId, 'user_id' => $userId]
        );

        return (int) ($row['total'] ?? 0);
    }

    public function markRead(int $notificationId, int $siteId, int $userId): bool
    {
        // `user_id` is part of the WHERE clause, so an id belonging to someone else simply
        // matches nothing rather than being readable.
        return $this->connection->affect(
            'UPDATE notifications SET read_at = :read_at '
            . 'WHERE id = :id AND site_id = :site_id AND user_id = :user_id AND read_at IS NULL',
            [
                'read_at' => $this->now(),
                'id' => $notificationId,
                'site_id' => $siteId,
                'user_id' => $userId,
            ]
        ) > 0;
    }

    public function markAllRead(int $siteId, int $userId): int
    {
        return $this->connection->affect(
            'UPDATE notifications SET read_at = :read_at '
            . 'WHERE site_id = :site_id AND user_id = :user_id AND read_at IS NULL',
            ['read_at' => $this->now(), 'site_id' => $siteId, 'user_id' => $userId]
        );
    }

    public function enqueue(int $siteId, string $recipient, string $subject, string $body, string $event): void
    {
        $now = $this->now();

        $this->connection->affect(
            'INSERT INTO notification_outbox '
            . '(site_id, recipient, subject, body, event, status, attempts, available_at, created_at) '
            . 'VALUES (:site_id, :recipient, :subject, :body, :event, :status, 0, :available_at, :created_at)',
            [
                'site_id' => $siteId,
                'recipient' => $recipient,
                'subject' => $subject,
                'body' => $body,
                'event' => $event,
                'status' => 'pending',
                'available_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function dueMessages(int $limit): array
    {
        return $this->connection->select(
            'SELECT id, site_id, recipient, subject, body, event, attempts FROM notification_outbox '
            . 'WHERE status = :status AND available_at <= :now ORDER BY id ASC LIMIT ' . max(1, $limit),
            ['status' => 'pending', 'now' => $this->now()]
        );
    }

    public function markSent(int $id): void
    {
        $this->connection->affect(
            'UPDATE notification_outbox SET status = :status, sent_at = :sent_at, last_error = NULL '
            . 'WHERE id = :id',
            ['status' => 'sent', 'sent_at' => $this->now(), 'id' => $id]
        );
    }

    public function markFailed(int $id, string $error, int $attempts, ?string $retryAt): void
    {
        $this->connection->affect(
            'UPDATE notification_outbox SET status = :status, attempts = :attempts, '
            . 'last_error = :error, available_at = :available_at WHERE id = :id',
            [
                'status' => $retryAt === null ? 'failed' : 'pending',
                'attempts' => $attempts,
                'error' => mb_substr($error, 0, 500),
                'available_at' => $retryAt ?? $this->now(),
                'id' => $id,
            ]
        );
    }

    public function pendingCount(): int
    {
        $row = $this->connection->selectOne(
            'SELECT COUNT(*) AS total FROM notification_outbox WHERE status = :status',
            ['status' => 'pending']
        );

        return (int) ($row['total'] ?? 0);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
