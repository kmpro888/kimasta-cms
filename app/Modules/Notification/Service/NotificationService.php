<?php

declare(strict_types=1);

namespace CMS\Modules\Notification\Service;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Entity\Content;
use CMS\Modules\Content\Exceptions\ContentNotFoundException;
use CMS\Modules\Identity\Contracts\RoleServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Modules\Notification\Contracts\MailTransportInterface;
use CMS\Modules\Notification\Contracts\NotificationServiceInterface;
use CMS\Modules\Notification\Entity\Notification;
use CMS\Modules\Notification\Repository\NotificationRepositoryInterface;
use CMS\Modules\Site\Contracts\SettingsServiceInterface;
use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\DomainEventInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;
use Throwable;

/**
 * Notifications.
 *
 * Three decisions worth stating:
 *
 *  1. **Internal delivery is synchronous, email is queued.** Writing an in-app row is a
 *     cheap insert. Sending mail is not: it blocks the request on a remote server and loses
 *     the message on timeout. So email goes to an outbox that cron drains — no worker
 *     process, which the production constraints forbid.
 *  2. **Event × channel is switchable, and email defaults to OFF.** A fresh install must not
 *     start mailing people because someone published a post. Internal defaults to ON, which
 *     is harmless.
 *  3. **Recipients are derived, never stored.** The author comes from the content row;
 *     reviewers come from the capability, so a role change immediately changes who is told.
 */
final class NotificationService implements NotificationServiceInterface
{
    private const NAMESPACE = 'notifications';

    /** Bounded retries: a permanently failing address must stop being retried. */
    private const MAX_ATTEMPTS = 3;

    /** Seconds before a failed message is retried, multiplied by the attempt number. */
    private const RETRY_BACKOFF = 300;

    public function __construct(
        private readonly NotificationRepositoryInterface $repository,
        private readonly ContentServiceInterface $contents,
        private readonly UserServiceInterface $users,
        private readonly RoleServiceInterface $roles,
        private readonly SettingsServiceInterface $settings,
        private readonly MailTransportInterface $mail,
        private readonly EventDispatcherInterface $events,
        private readonly ClockInterface $clock,
    ) {
    }

    public function subscribe(): void
    {
        foreach (self::EVENTS as $event) {
            $this->events->listen(
                $event,
                fn (DomainEventInterface $e): int => $this->handleEvent($e->name(), $e->payload()),
                5
            );
        }
    }

    public function handleEvent(string $event, array $payload): int
    {
        if (!in_array($event, self::EVENTS, true)) {
            return 0;
        }

        $siteId = isset($payload['site_id']) && is_numeric($payload['site_id'])
            ? (int) $payload['site_id']
            : 0;

        // A comment event carries `content_id`; a content event carries `id`. One of the two is the
        // article the notification is about.
        $contentId = 0;

        foreach (['content_id', 'id'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key]) && (int) $payload[$key] > 0) {
                $contentId = (int) $payload[$key];

                break;
            }
        }

        if ($siteId <= 0 || $contentId <= 0) {
            return 0;
        }

        try {
            $content = $this->contents->get($contentId, $siteId);
        } catch (ContentNotFoundException) {
            // The row is gone (or belongs to another tenant). Nothing to tell anyone.
            return 0;
        }

        $recipients = $this->recipientsFor($event, $siteId, $content, $payload);

        $created = 0;

        foreach ($recipients as $userId) {
            if ($this->isEnabled($siteId, $event, self::CHANNEL_INTERNAL)) {
                $this->repository->create($siteId, $userId, [
                    'event' => $event,
                    'title' => $this->titleFor($event, $content, $payload),
                    'body' => $this->bodyFor($event, $content, $payload),
                    'content_id' => $contentId,
                ]);

                $created++;
            }

            if (!$this->isEnabled($siteId, $event, self::CHANNEL_EMAIL)) {
                continue;
            }

            $user = $this->users->find($userId, $siteId);

            if ($user === null || $user->email === '') {
                continue;
            }

            $this->repository->enqueue(
                $siteId,
                $user->email,
                $this->titleFor($event, $content, $payload),
                $this->bodyFor($event, $content, $payload),
                $event,
            );

            $created++;
        }

        return $created;
    }

    public function listForUser(int $siteId, int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        return $this->repository->listForUser($siteId, $userId, $unreadOnly, max(1, min(100, $limit)));
    }

    public function unreadCount(int $siteId, int $userId): int
    {
        return $this->repository->unreadCount($siteId, $userId);
    }

    public function markRead(int $notificationId, int $siteId, int $userId): bool
    {
        return $this->repository->markRead($notificationId, $siteId, $userId);
    }

    public function markAllRead(int $siteId, int $userId): int
    {
        return $this->repository->markAllRead($siteId, $userId);
    }

    public function processOutbox(int $limit = 50): array
    {
        $sent = 0;
        $failed = 0;

        foreach ($this->repository->dueMessages(max(1, min(200, $limit))) as $message) {
            $id = (int) $message['id'];
            $attempts = (int) $message['attempts'] + 1;

            try {
                $this->mail->send(
                    (string) $message['recipient'],
                    (string) $message['subject'],
                    (string) $message['body'],
                );

                $this->repository->markSent($id);
                $sent++;

                continue;
            } catch (Throwable $exception) {
                // Bounded retry: a permanently invalid address must stop being retried
                // rather than spinning forever in the outbox.
                $retryAt = $attempts >= self::MAX_ATTEMPTS
                    ? null
                    : $this->clock->now()
                        ->modify('+' . (self::RETRY_BACKOFF * $attempts) . ' seconds')
                        ->format('Y-m-d H:i:s');

                $this->repository->markFailed($id, $exception->getMessage(), $attempts, $retryAt);
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    public function isEnabled(int $siteId, string $event, string $channel): bool
    {
        $default = $channel === self::CHANNEL_INTERNAL ? '1' : '0';
        $value = $this->settings->get($siteId, $this->key($event, $channel), self::NAMESPACE, $default);

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    public function setPreference(int $siteId, string $event, string $channel, bool $enabled): void
    {
        if (!in_array($channel, [self::CHANNEL_INTERNAL, self::CHANNEL_EMAIL], true)) {
            throw new \InvalidArgumentException('Unknown notification channel: ' . $channel);
        }

        if (!in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException('Unknown notification event: ' . $event);
        }

        $this->settings->set($siteId, $this->key($event, $channel), $enabled ? '1' : '0', self::NAMESPACE);
    }

    public function preferences(int $siteId): array
    {
        $out = [];

        foreach (self::EVENTS as $event) {
            $out[$event] = [
                self::CHANNEL_INTERNAL => $this->isEnabled($siteId, $event, self::CHANNEL_INTERNAL),
                self::CHANNEL_EMAIL => $this->isEnabled($siteId, $event, self::CHANNEL_EMAIL),
            ];
        }

        return $out;
    }

    /**
     * Who should hear about this event.
     *
     * Derived from the content row and the capability system, so a role change takes effect
     * immediately instead of leaving a stale recipient list behind.
     *
     * @return list<int>
     */
    private function recipientsFor(string $event, int $siteId, Content $content, array $payload = []): array
    {
        $authorId = $content->authorId;

        $recipients = match ($event) {
            // The author submitted it: the people who need to know are the reviewers.
            'content.submitted' => $this->roles->usersWithCapability('workflow.review', $siteId),

            // A comment waits for a moderator, so the moderators are the ones who need to know.
            'comment.created' => $this->roles->usersWithCapability('comment.moderate', $siteId),

            // The author of the comment, and nobody else: an anonymous commenter has no inbox.
            'comment.approved' => $this->commentAuthor($payload),

            // Everything else is news for the author.
            default => [$authorId],
        };

        // Never notify the author about their own submission, and never notify twice.
        if ($event === 'content.submitted') {
            $recipients = array_values(array_filter(
                $recipients,
                static fn (int $id): bool => $id !== $authorId
            ));
        }

        // Staff can comment too; telling them about their own comment is noise.
        if ($event === 'comment.created') {
            $commenter = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;

            if ($commenter > 0) {
                $recipients = array_values(array_filter(
                    $recipients,
                    static fn (int $id): bool => $id !== $commenter
                ));
            }

            // A reply is news for the person who was answered, as well as for the moderators.
            $parentUser = isset($payload['parent_user_id']) ? (int) $payload['parent_user_id'] : 0;

            if ($parentUser > 0) {
                $recipients[] = $parentUser;
            }
        }

        return array_values(array_unique($recipients));
    }

    /**
     * The author of a comment, if it was written by somebody with an account.
     *
     * @param array<string, mixed> $payload
     * @return list<int>
     */
    private function commentAuthor(array $payload): array
    {
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;

        return $userId > 0 ? [$userId] : [];
    }

    private function titleFor(string $event, Content $content, array $payload = []): string
    {
        if ($event === 'comment.created') {
            $name = trim((string) ($payload['author_name'] ?? ''));

            return 'New comment awaiting moderation' . ($name !== '' ? ' from ' . $name : '');
        }

        if ($event === 'comment.approved') {
            return 'Your comment was approved';
        }

        $label = match ($event) {
            'content.submitted' => 'Content submitted for review',
            'content.revision_requested' => 'Revision requested',
            'content.approved' => 'Content approved',
            'content.scheduled' => 'Content scheduled',
            'content.published' => 'Content published',
            default => 'Content update',
        };

        return $label . ': ' . $content->title;
    }

    private function bodyFor(string $event, Content $content, array $payload = []): string
    {
        if ($event === 'comment.created') {
            $excerpt = trim((string) ($payload['excerpt'] ?? ''));

            return 'On "' . $content->title . '"' . ($excerpt !== '' ? ': ' . $excerpt : '.');
        }

        if ($event === 'comment.approved') {
            return 'Your comment on "' . $content->title . '" is now visible.';
        }

        $action = match ($event) {
            'content.submitted' => 'has been submitted for review.',
            'content.revision_requested' => 'needs revision before it can be published.',
            'content.approved' => 'has been approved.',
            'content.scheduled' => 'has been scheduled for publication.',
            'content.published' => 'is now published.',
            default => 'was updated.',
        };

        return '"' . $content->title . '" ' . $action;
    }

    private function key(string $event, string $channel): string
    {
        return $event . '.' . $channel;
    }
}
