<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Comment;

use CMS\Core\Http\Request;
use CMS\Modules\Comment\Contracts\CommentServiceInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Notification\Contracts\NotificationServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * Replies, and the two emails a conversation produces.
 *
 * The threading itself already existed in the service — the migration proved it — but nothing on the
 * public site could *create* a reply, and nothing told either party that a conversation had moved.
 * Three separate failures, three separate assertions:
 *
 *  - a reply posted from the article page is stored as a reply, not as a new top-level comment;
 *  - a reply names the person being answered, and that state never reaches the page cache;
 *  - the person answered hears about it, and a commenter hears when their comment goes live.
 */
final class CommentReplyTest extends AdminTestCase
{
    /** Created once: the harness enforces unique usernames per site. */
    private ?int $writerId = null;

    private function article(string $slug, string $commentStatus = 'open'): int
    {
        $content = $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => 'Conversation ' . $slug,
            'slug' => $slug,
            'excerpt' => 'A summary.',
            'author_id' => $this->writerId ??= $this->makeUser('comment-writer', 'contributor'),
            'status' => 'published',
            'comment_status' => $commentStatus,
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);

        return (int) $content->id;
    }

    private function comments(): CommentServiceInterface
    {
        return $this->app->container()->get(CommentServiceInterface::class);
    }

    #[Test]
    public function aReplyPostedFromTheArticleIsStoredAsAReply(): void
    {
        $contentId = $this->article('reply-thread');

        $parent = $this->comments()->submit($this->siteId, $contentId, 0, 'The first word.', null, 'First Reader');
        $this->comments()->approve((int) $parent->id, $this->siteId);

        $response = $this->app->handle(Request::create('POST', '/comment', $this->host, [], [
            'content_id' => $contentId,
            'author_name' => 'Second Reader',
            'body' => 'A reply to the first word.',
            'parent_id' => (int) $parent->id,
        ]));

        self::assertSame(200, $response->status());

        $pending = $this->comments()->listForSite($this->siteId, 'pending');
        self::assertCount(1, $pending);
        self::assertSame((int) $parent->id, $pending[0]->parentId);

        $this->comments()->approve((int) $pending[0]->id, $this->siteId);

        $thread = $this->comments()->thread($this->siteId, $contentId);
        self::assertSame([0, 1], array_map(static fn ($c): int => (int) $c->depth, $thread));
    }

    #[Test]
    public function aReplyToACommentOnAnotherArticleIsRefused(): void
    {
        $first = $this->article('reply-a');
        $second = $this->article('reply-b');

        $parent = $this->comments()->submit($this->siteId, $second, 0, 'Elsewhere.', null, 'Other Reader');
        $this->comments()->approve((int) $parent->id, $this->siteId);

        $response = $this->app->handle(Request::create('POST', '/comment', $this->host, [], [
            'content_id' => $first,
            'author_name' => 'Confused Reader',
            'body' => 'Reply to the wrong article.',
            'parent_id' => (int) $parent->id,
        ]));

        // 404 rather than 422: a comment on another article is reported as absent, not as
        // present-but-forbidden.
        self::assertSame(404, $response->status());
    }

    #[Test]
    public function theReplyFormNamesThePersonAndIsNeverCached(): void
    {
        $contentId = $this->article('reply-form');
        $parent = $this->comments()->submit($this->siteId, $contentId, 0, 'Answer me.', null, 'Asking Reader');
        $this->comments()->approve((int) $parent->id, $this->siteId);

        // The query goes in its own argument: `create()` does not parse a query string out of the
        // path, and a test that passes `/blog/x?reply_to=5` exercises a path that never existed.
        $path = '/blog/reply-form';
        $query = ['reply_to' => (int) $parent->id];

        $first = $this->app->handle(Request::create('GET', $path, $this->host, $query));
        $html = $first->content();

        self::assertSame(200, $first->status());
        self::assertStringContainsString('Replying to Asking Reader', $html);
        self::assertStringContainsString('name="parent_id" value="' . (int) $parent->id . '"', $html);

        // Cached under this path it would be fine, but cached under `/blog/reply-form` it would leak
        // a reply form to the next reader. The page must be a miss every time.
        $second = $this->app->handle(Request::create('GET', $path, $this->host, $query));

        self::assertNotSame('HIT', (string) $second->header('X-Cache'));
        self::assertStringContainsString('Replying to Asking Reader', $second->content());
    }

    #[Test]
    public function aReplyNotifiesThePersonWhoWasAnswered(): void
    {
        $contentId = $this->article('reply-notify');
        $authorId = $this->makeUser('reader-with-account', 'contributor');

        $parent = $this->comments()->submit($this->siteId, $contentId, $authorId, 'Please answer.', null, 'Account Reader');
        $this->comments()->approve((int) $parent->id, $this->siteId);

        $this->comments()->submit($this->siteId, $contentId, 0, 'Here is the answer.', (int) $parent->id, 'Guest');

        $notifications = $this->app->container()->get(NotificationServiceInterface::class)
            ->listForUser($this->siteId, $authorId);

        $titles = array_map(static fn ($n): string => $n->title, $notifications);

        self::assertContains('New comment awaiting moderation from Guest', $titles);
    }

    #[Test]
    public function approvingACommentNotifiesItsAuthor(): void
    {
        $contentId = $this->article('approval-notify');
        $authorId = $this->makeUser('approved-reader', 'contributor');

        $comment = $this->comments()->submit($this->siteId, $contentId, $authorId, 'Was I approved?', null, 'Account Reader');

        $this->comments()->approve((int) $comment->id, $this->siteId);

        $notifications = $this->app->container()->get(NotificationServiceInterface::class)
            ->listForUser($this->siteId, $authorId);

        $titles = array_map(static fn ($n): string => $n->title, $notifications);

        self::assertContains('Your comment was approved', $titles);
    }

    #[Test]
    public function anAnonymousCommentHasNobodyToNotifyOnApproval(): void
    {
        $contentId = $this->article('anonymous-approval');

        // A moderator, so somebody is genuinely in the loop for the pending comment. The point is
        // that the *approval* notification has nobody to go to: the commenter has no account.
        $moderator = $this->makeUser('approval-moderator', 'editor');

        $comment = $this->comments()->submit($this->siteId, $contentId, 0, 'Anonymous words.', null, 'Guest');
        $this->comments()->approve((int) $comment->id, $this->siteId);

        $titles = array_map(
            static fn ($n): string => $n->title,
            $this->app->container()->get(NotificationServiceInterface::class)
                ->listForUser($this->siteId, $moderator)
        );

        self::assertContains('New comment awaiting moderation from Guest', $titles);
        self::assertNotContains('Your comment was approved', $titles);
    }
}
