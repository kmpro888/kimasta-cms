<?php

declare(strict_types=1);

namespace CMS\Modules\Notification\Contracts;

use CMS\Modules\Notification\Entity\Notification;

/**
 * Notifications across two channels.
 *
 * Channels are independent and each event/channel pair can be switched off, because a
 * site may want in-app notices without email — and email is the channel that can annoy
 * people or get the domain blocked.
 *
 * Email is **queued, never sent inline**. A publish request must not wait on a mail
 * server, and a failed send must not lose the message. `processOutbox()` is drained by
 * cron: no long-running worker is required, which the production constraints forbid.
 *
 * FROZEN CONTRACT.
 */
interface NotificationServiceInterface
{
    /** Event names this module reacts to. */
    public const EVENTS = [
        'content.submitted',
        'content.revision_requested',
        'content.approved',
        'content.scheduled',
        'content.published',
        // A visitor's comment waits for a moderator; the queue was silent until this existed.
        'comment.created',
        // The other half of the conversation: the commenter hears back when it goes live.
        'comment.approved',
    ];

    public const CHANNEL_INTERNAL = 'internal';
    public const CHANNEL_EMAIL = 'email';

    /**
     * Route one domain event to recipients, writing in-app rows and queueing email.
     *
     * @param array<string, mixed> $payload
     *
     * @return int number of notifications created (both channels)
     */
    public function handleEvent(string $event, array $payload): int;

    /**
     * Subscribe to the content events. Called once during boot.
     */
    public function subscribe(): void;

    /**
     * @return list<Notification>
     */
    public function listForUser(int $siteId, int $userId, bool $unreadOnly = false, int $limit = 50): array;

    public function unreadCount(int $siteId, int $userId): int;

    /**
     * Mark one notification read. A notification belonging to someone else is a no-op,
     * so an id cannot be used to probe other users' inboxes.
     */
    public function markRead(int $notificationId, int $siteId, int $userId): bool;

    public function markAllRead(int $siteId, int $userId): int;

    /**
     * Drain due email messages. Called by cron.
     *
     * @return array{sent: int, failed: int}
     */
    public function processOutbox(int $limit = 50): array;

    public function isEnabled(int $siteId, string $event, string $channel): bool;

    public function setPreference(int $siteId, string $event, string $channel, bool $enabled): void;

    /**
     * Every event crossed with both channels, with its current state.
     *
     * @return array<string, array{internal: bool, email: bool}>
     */
    public function preferences(int $siteId): array;
}
