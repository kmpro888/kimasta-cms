<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Notification;

use CMS\Modules\Notification\Contracts\MailTransportInterface;
use CMS\Modules\Notification\Contracts\NotificationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * Notifications.
 *
 * The properties worth pinning: in-app delivery is synchronous, email is queued and drained
 * by cron, each event/channel pair is switchable, and a failing address stops being retried.
 */
#[CoversClass(\CMS\Modules\Notification\Service\NotificationService::class)]
final class NotificationServiceTest extends AppTestCase
{
    private NotificationServiceInterface $notifications;

    private int $authorId;

    private int $editorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notifications = $this->app->container()->get(NotificationServiceInterface::class);

        $this->authorId = $this->makeUser('author', 'contributor');
        $this->editorId = $this->makeUser('editor', 'editor');
    }

    /**
     * A content row authored by the contributor.
     */
    private function article(string $title = 'A Post'): int
    {
        return (int) $this->app->container()->get(\CMS\Modules\Content\Contracts\ContentServiceInterface::class)
            ->create($this->siteId, [
                'title' => $title,
                'author_id' => $this->authorId,
                'status' => 'published',
                'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body']]],
            ])->id;
    }

    #[Test]
    public function internalNotificationsAreOnByDefaultAndEmailIsOff(): void
    {
        // A fresh install must not start mailing people because someone published a post.
        self::assertTrue($this->notifications->isEnabled($this->siteId, 'content.published', 'internal'));
        self::assertFalse($this->notifications->isEnabled($this->siteId, 'content.published', 'email'));
    }

    #[Test]
    public function publishingNotifiesTheAuthorInApp(): void
    {
        $contentId = $this->article('Published Piece');

        $created = $this->notifications->handleEvent('content.published', [
            'id' => $contentId,
            'site_id' => $this->siteId,
        ]);

        self::assertSame(1, $created);

        $inbox = $this->notifications->listForUser($this->siteId, $this->authorId);
        self::assertCount(1, $inbox);
        self::assertSame('content.published', $inbox[0]->event);
        self::assertStringContainsString('Published Piece', $inbox[0]->title);
    }

    #[Test]
    public function submissionNotifiesReviewersNotTheAuthor(): void
    {
        $contentId = $this->article('Submitted Piece');

        $this->notifications->handleEvent('content.submitted', [
            'id' => $contentId,
            'site_id' => $this->siteId,
        ]);

        // The editor holds `workflow.review`; the author does not, and must not be told
        // about their own submission.
        self::assertCount(1, $this->notifications->listForUser($this->siteId, $this->editorId));
        self::assertCount(0, $this->notifications->listForUser($this->siteId, $this->authorId));
    }

    #[Test]
    public function emailIsQueuedNotSent(): void
    {
        $this->notifications->setPreference($this->siteId, 'content.published', 'email', true);

        $contentId = $this->article();
        $this->notifications->handleEvent('content.published', ['id' => $contentId, 'site_id' => $this->siteId]);

        $pending = $this->db->selectOne(
            'SELECT COUNT(*) AS total FROM notification_outbox WHERE status = :s',
            ['s' => 'pending']
        );

        // Queued, and still pending: nothing has tried to send it yet.
        self::assertSame(1, (int) $pending['total']);
    }

    #[Test]
    public function theOutboxIsDrainedByProcessOutbox(): void
    {
        $this->notifications->setPreference($this->siteId, 'content.published', 'email', true);

        $contentId = $this->article();
        $this->notifications->handleEvent('content.published', ['id' => $contentId, 'site_id' => $this->siteId]);

        // Swap in a transport that records instead of sending, exactly as cron would use
        // the real one.
        $transport = new class implements MailTransportInterface {
            /** @var list<array{to: string, subject: string}> */
            public array $sent = [];

            public function send(string $to, string $subject, string $body): void
            {
                $this->sent[] = ['to' => $to, 'subject' => $subject];
            }
        };

        $service = $this->serviceWith($transport);

        $result = $service->processOutbox();

        self::assertSame(1, $result['sent']);
        self::assertSame(0, $result['failed']);
        self::assertCount(1, $transport->sent);
        self::assertSame('author@example.com', $transport->sent[0]['to']);

        $status = $this->db->selectOne('SELECT status FROM notification_outbox LIMIT 1');
        self::assertSame('sent', (string) $status['status']);
    }

    #[Test]
    public function aFailingTransportIsRetriedThenAbandoned(): void
    {
        $this->notifications->setPreference($this->siteId, 'content.published', 'email', true);

        $contentId = $this->article();
        $this->notifications->handleEvent('content.published', ['id' => $contentId, 'site_id' => $this->siteId]);

        $transport = new class implements MailTransportInterface {
            public function send(string $to, string $subject, string $body): void
            {
                throw new \RuntimeException('relay refused');
            }
        };

        $service = $this->serviceWith($transport);

        // A permanently failing address must stop being retried rather than sitting in the
        // outbox forever.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $service->processOutbox();

            // Make the retry due immediately rather than waiting the backoff.
            $this->db->affect('UPDATE notification_outbox SET available_at = :now', [
                'now' => gmdate('Y-m-d H:i:s'),
            ]);
        }

        $row = $this->db->selectOne('SELECT status, attempts FROM notification_outbox LIMIT 1');

        self::assertSame('failed', (string) $row['status']);
        self::assertSame(3, (int) $row['attempts']);
    }

    #[Test]
    public function switchingAChannelOffStopsDelivery(): void
    {
        $this->notifications->setPreference($this->siteId, 'content.published', 'internal', false);

        $contentId = $this->article();
        $created = $this->notifications->handleEvent('content.published', [
            'id' => $contentId,
            'site_id' => $this->siteId,
        ]);

        self::assertSame(0, $created);
        self::assertCount(0, $this->notifications->listForUser($this->siteId, $this->authorId));
    }

    #[Test]
    public function bothChannelsCanBeOnAtOnce(): void
    {
        $this->notifications->setPreference($this->siteId, 'content.published', 'email', true);

        $contentId = $this->article();
        $created = $this->notifications->handleEvent('content.published', [
            'id' => $contentId,
            'site_id' => $this->siteId,
        ]);

        // One in-app row plus one queued email.
        self::assertSame(2, $created);
    }

    #[Test]
    public function anUnknownEventIsIgnored(): void
    {
        $contentId = $this->article();

        self::assertSame(0, $this->notifications->handleEvent('content.unknown', [
            'id' => $contentId,
            'site_id' => $this->siteId,
        ]));
    }

    #[Test]
    public function aCrossSiteContentIdProducesNothing(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        $foreignId = (int) $this->app->container()->get(\CMS\Modules\Content\Contracts\ContentServiceInterface::class)
            ->create($otherSite, [
                'title' => 'Foreign',
                'author_id' => 1,
                'status' => 'published',
            ])->id;

        // The event names this site but the content belongs to another: nothing is sent.
        self::assertSame(0, $this->notifications->handleEvent('content.published', [
            'id' => $foreignId,
            'site_id' => $this->siteId,
        ]));
    }

    #[Test]
    public function unreadCountAndMarkingWork(): void
    {
        $contentId = $this->article();
        $this->notifications->handleEvent('content.published', ['id' => $contentId, 'site_id' => $this->siteId]);
        $this->notifications->handleEvent('content.approved', ['id' => $contentId, 'site_id' => $this->siteId]);

        self::assertSame(2, $this->notifications->unreadCount($this->siteId, $this->authorId));

        $first = $this->notifications->listForUser($this->siteId, $this->authorId)[0];
        self::assertTrue($this->notifications->markRead((int) $first->id, $this->siteId, $this->authorId));

        self::assertSame(1, $this->notifications->unreadCount($this->siteId, $this->authorId));

        self::assertSame(1, $this->notifications->markAllRead($this->siteId, $this->authorId));
        self::assertSame(0, $this->notifications->unreadCount($this->siteId, $this->authorId));
    }

    #[Test]
    public function anotherUsersNotificationCannotBeMarkedRead(): void
    {
        $contentId = $this->article();
        $this->notifications->handleEvent('content.published', ['id' => $contentId, 'site_id' => $this->siteId]);

        $notification = $this->notifications->listForUser($this->siteId, $this->authorId)[0];

        // The editor does not own it, so the id matches nothing.
        self::assertFalse($this->notifications->markRead((int) $notification->id, $this->siteId, $this->editorId));
        self::assertSame(1, $this->notifications->unreadCount($this->siteId, $this->authorId));
    }

    #[Test]
    public function notificationsAreIsolatedPerSite(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        $contentId = $this->article();
        $this->notifications->handleEvent('content.published', ['id' => $contentId, 'site_id' => $this->siteId]);

        self::assertCount(1, $this->notifications->listForUser($this->siteId, $this->authorId));
        self::assertCount(0, $this->notifications->listForUser($otherSite, $this->authorId));
    }

    #[Test]
    public function thePreferenceMatrixCoversEveryEventAndChannel(): void
    {
        $preferences = $this->notifications->preferences($this->siteId);

        foreach (NotificationServiceInterface::EVENTS as $event) {
            self::assertArrayHasKey($event, $preferences);
            self::assertArrayHasKey('internal', $preferences[$event]);
            self::assertArrayHasKey('email', $preferences[$event]);
        }
    }

    #[Test]
    public function anUnknownChannelIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->notifications->setPreference($this->siteId, 'content.published', 'sms', true);
    }

    #[Test]
    public function anUnknownEventPreferenceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->notifications->setPreference($this->siteId, 'content.unknown', 'email', true);
    }

    /**
     * A service instance using a specific mail transport.
     */
    private function serviceWith(MailTransportInterface $transport): NotificationServiceInterface
    {
        $c = $this->app->container();

        return new \CMS\Modules\Notification\Service\NotificationService(
            $c->get(\CMS\Modules\Notification\Repository\NotificationRepositoryInterface::class),
            $c->get(\CMS\Modules\Content\Contracts\ContentServiceInterface::class),
            $c->get(\CMS\Modules\Identity\Contracts\UserServiceInterface::class),
            $c->get(\CMS\Modules\Identity\Contracts\RoleServiceInterface::class),
            $c->get(\CMS\Modules\Site\Contracts\SettingsServiceInterface::class),
            $transport,
            $c->get(\CMS\Shared\Contracts\EventDispatcherInterface::class),
            $c->get(\CMS\Shared\Contracts\ClockInterface::class),
        );
    }
}
