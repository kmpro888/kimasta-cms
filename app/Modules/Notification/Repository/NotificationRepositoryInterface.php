<?php

declare(strict_types=1);

namespace CMS\Modules\Notification\Repository;

use CMS\Modules\Notification\Entity\Notification;

/**
 * `notifications` and `notification_outbox` persistence.
 */
interface NotificationRepositoryInterface
{
    public function create(int $siteId, int $userId, array $attributes): Notification;

    /**
     * @return list<Notification>
     */
    public function listForUser(int $siteId, int $userId, bool $unreadOnly, int $limit): array;

    public function unreadCount(int $siteId, int $userId): int;

    /**
     * Mark one row read, scoped to its owner. Returns false when it is not theirs.
     */
    public function markRead(int $notificationId, int $siteId, int $userId): bool;

    public function markAllRead(int $siteId, int $userId): int;

    /**
     * Queue an email message.
     */
    public function enqueue(int $siteId, string $recipient, string $subject, string $body, string $event): void;

    /**
     * Messages whose `available_at` has passed, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function dueMessages(int $limit): array;

    public function markSent(int $id): void;

    public function markFailed(int $id, string $error, int $attempts, ?string $retryAt): void;

    public function pendingCount(): int;
}
