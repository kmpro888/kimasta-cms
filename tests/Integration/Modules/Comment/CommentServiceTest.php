<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Comment;

use CMS\Modules\Comment\Contracts\CommentServiceInterface;
use CMS\Modules\Comment\Entity\Comment;
use CMS\Modules\Comment\Exceptions\CommentDepthExceededException;
use CMS\Modules\Comment\Exceptions\CommentNotFoundException;
use CMS\Modules\Comment\Exceptions\CommentsClosedException;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * Comment threads.
 *
 * The acceptance criteria from the task pack are the tests: the approved count stays
 * correct, and nesting uses a bounded depth.
 */
#[CoversClass(\CMS\Modules\Comment\Service\CommentService::class)]
final class CommentServiceTest extends AppTestCase
{
    private CommentServiceInterface $comments;

    private int $authorId;

    private int $contentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comments = $this->app->container()->get(CommentServiceInterface::class);
        $this->authorId = $this->makeUser('commenter');
        $this->contentId = $this->publishArticle('Commented Article');
    }

    private function submit(string $body = 'A first comment.', ?int $parentId = null): Comment
    {
        return $this->comments->submit($this->siteId, $this->contentId, $this->authorId, $body, $parentId);
    }

    #[Test]
    public function aNewCommentStartsPending(): void
    {
        $comment = $this->submit();

        // Auto-approving would put unreviewed text on a public page.
        self::assertSame(Comment::STATUS_PENDING, $comment->status);
        self::assertFalse($comment->isVisible());
    }

    #[Test]
    public function aPendingCommentIsNotInThePublicThread(): void
    {
        $this->submit();

        self::assertSame([], $this->comments->thread($this->siteId, $this->contentId));
    }

    #[Test]
    public function approvingPutsTheCommentInTheThread(): void
    {
        $comment = $this->submit();
        $this->comments->approve((int) $comment->id, $this->siteId);

        $thread = $this->comments->thread($this->siteId, $this->contentId);

        self::assertCount(1, $thread);
        self::assertSame($comment->body, $thread[0]->body);
    }

    #[Test]
    public function theApprovedCountTracksApproval(): void
    {
        $first = $this->submit('One.');
        $this->submit('Two.');

        self::assertSame(0, $this->comments->approvedCount($this->siteId, $this->contentId));

        $this->comments->approve((int) $first->id, $this->siteId);

        self::assertSame(1, $this->comments->approvedCount($this->siteId, $this->contentId));
    }

    #[Test]
    public function trashingRemovesTheCommentFromTheCount(): void
    {
        $comment = $this->submit();
        $this->comments->approve((int) $comment->id, $this->siteId);
        self::assertSame(1, $this->comments->approvedCount($this->siteId, $this->contentId));

        $this->comments->trash((int) $comment->id, $this->siteId);

        self::assertSame(0, $this->comments->approvedCount($this->siteId, $this->contentId));
    }

    #[Test]
    public function spamIsADistinctStateFromTrash(): void
    {
        $comment = $this->submit();
        $marked = $this->comments->markSpam((int) $comment->id, $this->siteId);

        self::assertSame(Comment::STATUS_SPAM, $marked->status);
        self::assertNotSame(Comment::STATUS_TRASH, $marked->status);
    }

    #[Test]
    public function approvingTwiceDoesNotEmitTwoEvents(): void
    {
        $dispatcher = new \Tests\Support\RecordingEventDispatcher();

        $service = new \CMS\Modules\Comment\Service\CommentService(
            $this->app->container()->get(\CMS\Modules\Comment\Repository\CommentRepositoryInterface::class),
            $this->app->container()->get(ContentServiceInterface::class),
            $this->app->container()->get(\CMS\Modules\Identity\Contracts\UserServiceInterface::class),
            $dispatcher,
            new \Tests\Support\FrozenClock(),
        );

        $comment = $service->submit($this->siteId, $this->contentId, $this->authorId, 'Body.');

        $service->approve((int) $comment->id, $this->siteId);
        $service->approve((int) $comment->id, $this->siteId);

        // A notification listener must not fire twice for one moderation decision.
        $approvedEvents = array_filter(
            $dispatcher->names(),
            static fn (string $name): bool => $name === 'comment.approved'
        );

        self::assertCount(1, $approvedEvents);
    }

    #[Test]
    public function aReplyInheritsOneLevelOfDepth(): void
    {
        $parent = $this->submit('Parent.');
        $this->comments->approve((int) $parent->id, $this->siteId);

        $reply = $this->submit('Reply.', (int) $parent->id);

        self::assertSame(1, $reply->depth);
        self::assertSame($parent->id, $reply->parentId);
    }

    #[Test]
    public function nestingIsBounded(): void
    {
        $parentId = null;
        $depth = 0;

        // Build a chain up to the limit, then prove one more is refused.
        while ($depth <= CommentServiceInterface::MAX_DEPTH) {
            $comment = $this->submit('Level ' . $depth, $parentId);
            $this->comments->approve((int) $comment->id, $this->siteId);
            $parentId = (int) $comment->id;
            $depth++;
        }

        $this->expectException(CommentDepthExceededException::class);

        $this->submit('Too deep.', $parentId);
    }

    #[Test]
    public function aReplyToAPendingCommentIsRefused(): void
    {
        // An unapproved parent is not visible, so replying to it would create an
        // orphan in the public thread.
        $parent = $this->submit('Still pending.');

        $this->expectException(CommentNotFoundException::class);

        $this->submit('Reply.', (int) $parent->id);
    }

    #[Test]
    public function aReplyCannotCrossToAnotherArticle(): void
    {
        $otherContentId = $this->publishArticle('Other Article');

        $parent = $this->submit('Parent here.');
        $this->comments->approve((int) $parent->id, $this->siteId);

        // Same site, different content: the parent belongs to another thread.
        $this->expectException(CommentNotFoundException::class);

        $this->comments->submit($this->siteId, $otherContentId, $this->authorId, 'Wrong thread.', (int) $parent->id);
    }

    #[Test]
    public function commentsOnAClosedArticleAreRefused(): void
    {
        $this->app->container()->get(ContentServiceInterface::class)
            ->update($this->contentId, $this->siteId, ['comment_status' => 'closed']);

        $this->expectException(CommentsClosedException::class);

        $this->submit();
    }

    #[Test]
    public function commentsOnADraftAreRefused(): void
    {
        $draftId = (int) $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'title' => 'Draft Article',
            'author_id' => 1,
        ])->id;

        $this->expectException(CommentNotFoundException::class);

        $this->comments->submit($this->siteId, $draftId, $this->authorId, 'Body.');
    }

    #[Test]
    public function commentsAreIsolatedPerSite(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        $comment = $this->submit('Mine.');
        $this->comments->approve((int) $comment->id, $this->siteId);

        self::assertSame(0, $this->comments->countForSite($otherSite));
        self::assertCount(1, $this->comments->thread($this->siteId, $this->contentId));
    }

    #[Test]
    public function aCrossSiteCommentIdIsRejected(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');
        $comment = $this->submit();

        $this->expectException(CommentNotFoundException::class);

        $this->comments->approve((int) $comment->id, $otherSite);
    }

    #[Test]
    public function deletingRemovesRepliesThroughTheCascade(): void
    {
        $parent = $this->submit('Parent.');
        $this->comments->approve((int) $parent->id, $this->siteId);
        $reply = $this->submit('Reply.', (int) $parent->id);
        $this->comments->approve((int) $reply->id, $this->siteId);

        self::assertSame(2, $this->comments->countForSite($this->siteId));

        $this->comments->delete((int) $parent->id, $this->siteId);

        // A deleted parent must not leave orphan replies pointing at a missing row.
        self::assertSame(0, $this->comments->countForSite($this->siteId));
    }

    #[Test]
    public function theModerationQueueIsFilteredByStatus(): void
    {
        $approved = $this->submit('Approved one.');
        $this->comments->approve((int) $approved->id, $this->siteId);
        $this->submit('Pending one.');

        self::assertCount(1, $this->comments->listForSite($this->siteId, Comment::STATUS_PENDING));
        self::assertCount(1, $this->comments->listForSite($this->siteId, Comment::STATUS_APPROVED));
        self::assertCount(2, $this->comments->listForSite($this->siteId));
    }

    #[Test]
    public function aBodyThatIsTooShortIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->submit('x');
    }

    #[Test]
    public function theAuthorDisplayNameIsCapturedAtSubmission(): void
    {
        $comment = $this->submit();

        // Denormalised on purpose: renaming a user must not rewrite old threads.
        self::assertSame('Commenter', $comment->authorName);
    }
}
