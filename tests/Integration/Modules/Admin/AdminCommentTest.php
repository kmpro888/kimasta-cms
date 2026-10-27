<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use CMS\Modules\Comment\Contracts\CommentServiceInterface;
use CMS\Modules\Comment\Entity\Comment;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * Comment moderation.
 *
 * Comments start `pending`, which is what makes a public write endpoint survivable. This screen
 * is where that decision is acted on.
 *
 * Note what is deliberately not tested here: there is no public comment form. `comments.user_id`
 * is NOT NULL and the only sign-in is the staff admin, so a reader cannot post. That is a product
 * decision waiting to be made, not an oversight to paper over with a form that would 401.
 */
#[CoversClass(\CMS\Modules\Admin\Http\Controller\CommentController::class)]
final class AdminCommentTest extends AdminTestCase
{
    private ?int $authorId = null;

    private function author(): int
    {
        return $this->authorId ??= $this->makeUser('writer', 'contributor');
    }

    private function article(string $title): int
    {
        return (int) $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'author_id' => $this->author(),
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ])->id;
    }

    private function comment(int $contentId, string $body = 'A remark.'): Comment
    {
        return $this->app->container()->get(CommentServiceInterface::class)->submit(
            $this->siteId,
            $contentId,
            $this->author(),
            $body,
        );
    }

    private function statusOf(int $commentId): string
    {
        foreach ($this->app->container()->get(CommentServiceInterface::class)->listForSite($this->siteId, null, 50) as $comment) {
            if ((int) $comment->id === $commentId) {
                return $comment->status;
            }
        }

        return 'missing';
    }

    #[Test]
    public function theQueueRequiresTheModerateCapability(): void
    {
        $this->loginAs($this->makeUser('writer', 'contributor'));

        // A contributor can post a comment but must not moderate one.
        self::assertSame(403, $this->get('/admin/comments')->status());
    }

    #[Test]
    public function anEditorSeesThePendingQueueAndTheArticleTitle(): void
    {
        $contentId = $this->article('Comments On This');
        $this->comment($contentId, 'Please read this.');

        $this->loginAs($this->makeUser('boss', 'editor'));

        $response = $this->get('/admin/comments');
        $html = $this->body($response);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Please read this.', $html);
        self::assertStringContainsString('Comments On This', $html, 'The queue must name the article, not an id.');
        self::assertStringContainsString('Approve', $html);
        self::assertStringContainsString('Spam', $html);
    }

    #[Test]
    public function theCommentsMenuEntryIsOnlyShownToSomeoneWhoCanUseIt(): void
    {
        $this->loginAs($this->makeUser('writer', 'contributor'));
        self::assertStringNotContainsString('href="/admin/comments"', $this->body($this->get('/admin')));

        $this->loginAs($this->makeUser('boss', 'editor'));
        self::assertStringContainsString('href="/admin/comments"', $this->body($this->get('/admin')));
    }

    #[Test]
    public function approvingACommentMakesItPublic(): void
    {
        $contentId = $this->article('Gets A Comment');
        $comment = $this->comment($contentId, 'Now you see me.');

        $this->loginAs($this->makeUser('boss', 'editor'));

        $response = $this->post('/admin/comments/' . $comment->id . '/approve', ['status' => 'pending']);

        self::assertSame(303, $response->status());
        self::assertSame('/admin/comments?status=pending', $this->location($response));
        self::assertSame(Comment::STATUS_APPROVED, $this->statusOf((int) $comment->id));

        // The reader's page is the point of approving.
        $page = $this->app->handle(\CMS\Core\Http\Request::create(
            'GET',
            '/blog/' . $this->app->container()->get(ContentServiceInterface::class)->get($contentId, $this->siteId)->slug,
            $this->host,
        ));

        self::assertStringContainsString('Now you see me.', $page->content());
    }

    #[Test]
    public function spamAndTrashMoveACommentOutOfTheWay(): void
    {
        $contentId = $this->article('Moved Around');
        $first = $this->comment($contentId, 'Spam me.');
        $second = $this->comment($contentId, 'Bin me.');

        $this->loginAs($this->makeUser('boss', 'editor'));

        $this->post('/admin/comments/' . $first->id . '/spam', ['status' => 'pending']);
        $this->post('/admin/comments/' . $second->id . '/trash', ['status' => 'pending']);

        self::assertSame(Comment::STATUS_SPAM, $this->statusOf((int) $first->id));
        self::assertSame(Comment::STATUS_TRASH, $this->statusOf((int) $second->id));
    }

    #[Test]
    public function aModerationActionRequiresACsrfToken(): void
    {
        $contentId = $this->article('No Token');
        $comment = $this->comment($contentId, 'Protected.');

        $this->loginAs($this->makeUser('boss', 'editor'));

        $response = $this->post('/admin/comments/' . $comment->id . '/approve', ['status' => 'pending'], false);

        self::assertSame(403, $response->status());
        self::assertSame(Comment::STATUS_PENDING, $this->statusOf((int) $comment->id));
    }

    #[Test]
    public function aCommentFromAnotherTenantIsA404(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        // The commenter must belong to that tenant: a user of this site does not exist there.
        $foreignAuthor = (int) $this->app->container()
            ->get(\CMS\Modules\Identity\Contracts\UserServiceInterface::class)
            ->create($otherSite, [
                'username' => 'theirs',
                'email' => 'theirs@example.com',
                'password' => 'correct-horse-battery',
                'display_name' => 'Someone Else',
            ])->id;

        $foreignContent = (int) $this->app->container()->get(ContentServiceInterface::class)->create($otherSite, [
            'type' => 'post',
            'title' => 'Elsewhere',
            'slug' => 'elsewhere',
            'author_id' => $foreignAuthor,
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ])->id;

        $foreign = $this->app->container()->get(CommentServiceInterface::class)
            ->submit($otherSite, $foreignContent, $foreignAuthor, 'Belongs to the other site.');

        $this->loginAs($this->makeUser('boss', 'editor'));

        self::assertSame(404, $this->post('/admin/comments/' . $foreign->id . '/approve')->status());
        self::assertSame(404, $this->post('/admin/comments/' . $foreign->id . '/delete')->status());
    }

    #[Test]
    public function theQueueFiltersByStatus(): void
    {
        $contentId = $this->article('Filter Me');
        $pending = $this->comment($contentId, 'Still pending.');
        $approved = $this->comment($contentId, 'Already approved.');

        $this->loginAs($this->makeUser('boss', 'editor'));
        $this->post('/admin/comments/' . $approved->id . '/approve', ['status' => 'pending']);

        $pendingPage = $this->body($this->get('/admin/comments', ['status' => 'pending']));
        self::assertStringContainsString('Still pending.', $pendingPage);
        self::assertStringNotContainsString('Already approved.', $pendingPage);

        $approvedPage = $this->body($this->get('/admin/comments', ['status' => 'approved']));
        self::assertStringContainsString('Already approved.', $approvedPage);
        self::assertStringNotContainsString('Still pending.', $approvedPage);
    }

    #[Test]
    public function anUnknownStatusFallsBackToPending(): void
    {
        $contentId = $this->article('Fallback');
        $this->comment($contentId, 'Visible in the fallback queue.');

        $this->loginAs($this->makeUser('boss', 'editor'));

        $html = $this->body($this->get('/admin/comments', ['status' => 'nonsense']));

        self::assertStringContainsString('Visible in the fallback queue.', $html);
    }
}
