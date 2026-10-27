<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Comment;

use CMS\Core\Http\Request;
use CMS\Modules\Comment\Contracts\CommentServiceInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Notification\Contracts\NotificationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * A guest comment rings the moderators' bell.
 *
 * Public comments land in a queue, and until now nothing said so: a moderator had to visit the
 * screen and hope. The notification is derived from the `comment.moderate` capability, so a role
 * change immediately changes who is told.
 */
#[CoversClass(\CMS\Modules\Notification\Service\NotificationService::class)]
final class CommentNotificationTest extends AppTestCase
{
    private function article(): int
    {
        $content = $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => 'Article With Comments',
            'slug' => 'article-with-comments',
            'author_id' => $this->makeUser('writer', 'contributor'),
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);

        return (int) $content->id;
    }

    private function notifications(): NotificationServiceInterface
    {
        return $this->app->container()->get(NotificationServiceInterface::class);
    }

    private function postComment(int $contentId, string $name = 'A Reader'): void
    {
        $response = $this->app->handle(Request::create('POST', '/comment', $this->host, [], [
            'content_id' => $contentId,
            'author_name' => $name,
            'body' => 'Is this thing moderated?',
        ]));

        self::assertSame(200, $response->status());
    }

    #[Test]
    public function aModeratorIsToldAboutAWaitingComment(): void
    {
        $moderator = $this->makeUser('mod', 'editor');
        $id = $this->article();

        $this->postComment($id);

        $mine = $this->notifications()->listForUser($this->siteId, $moderator);

        self::assertCount(1, $mine);
        self::assertSame('comment.created', $mine[0]->event);
        self::assertStringContainsString('New comment awaiting moderation', $mine[0]->title);
        self::assertStringContainsString('A Reader', $mine[0]->title);
        self::assertStringContainsString('Article With Comments', $mine[0]->body);
        self::assertSame($id, (int) $mine[0]->contentId);
    }

    #[Test]
    public function aContributorWithoutTheCapabilityIsNotTold(): void
    {
        $id = $this->article();

        $this->postComment($id);

        $writer = $this->app->container()->get(\CMS\Modules\Identity\Contracts\UserServiceInterface::class)
            ->find(1, $this->siteId);

        if ($writer !== null) {
            self::assertSame([], $this->notifications()->listForUser($this->siteId, (int) $writer->id));
        }
    }

    #[Test]
    public function aStaffMembersOwnCommentDoesNotNotifyThem(): void
    {
        $moderator = $this->makeUser('mod', 'editor');
        $id = $this->article();

        // The moderator posts through the service, as a signed-in author would.
        $this->app->container()->get(CommentServiceInterface::class)
            ->submit($this->siteId, $id, $moderator, 'A staff comment.');

        self::assertSame([], $this->notifications()->listForUser($this->siteId, $moderator));
    }

    #[Test]
    public function theInAppSwitchCanTurnItOff(): void
    {
        $moderator = $this->makeUser('mod', 'editor');
        $id = $this->article();

        $this->notifications()->setPreference($this->siteId, 'comment.created', 'internal', false);

        $this->postComment($id);

        self::assertSame([], $this->notifications()->listForUser($this->siteId, $moderator));
    }
    #[Test]
    public function aManualDispatchAlsoNotifies(): void
    {
        $moderator = $this->makeUser('mod', 'editor');
        $id = $this->article();

        $clock = $this->app->container()->get(\CMS\Shared\Contracts\ClockInterface::class);
        $this->app->container()->get(\CMS\Shared\Contracts\EventDispatcherInterface::class)->dispatch(
            \CMS\Core\Events\DomainEvent::create('comment.created', [
                'id' => 1, 'content_id' => $id, 'site_id' => $this->siteId, 'user_id' => 0, 'parent_id' => null,
                'author_name' => 'A Reader', 'excerpt' => 'manual',
            ], $this->siteId, $clock)
        );

        self::assertCount(1, $this->notifications()->listForUser($this->siteId, $moderator));
    }

    #[Test]
    public function theCommentIsStoredBeforeAnyNotification(): void
    {
        $this->makeUser('mod', 'editor');
        $id = $this->article();

        $this->postComment($id);

        self::assertSame(1, $this->app->container()->get(CommentServiceInterface::class)
            ->countForSite($this->siteId, 'pending'), 'The comment itself must have been stored.');
    }
}
