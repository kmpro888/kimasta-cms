<?php

declare(strict_types=1);

namespace CMS\Modules\Comment\Service;

use CMS\Core\Events\DomainEvent;
use CMS\Modules\Comment\Contracts\CommentServiceInterface;
use CMS\Modules\Comment\Entity\Comment;
use CMS\Modules\Comment\Exceptions\CommentDepthExceededException;
use CMS\Modules\Comment\Exceptions\CommentNotFoundException;
use CMS\Modules\Comment\Exceptions\CommentsClosedException;
use CMS\Modules\Comment\Repository\CommentRepositoryInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Exceptions\ContentNotFoundException;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;

/**
 * Comment threads.
 *
 * Three rules carry the design:
 *
 *  1. Submission requires an authenticated user and open comments on the content.
 *  2. Reply depth is bounded at write time, so rendering is a flat loop and a thread
 *     can always be paginated.
 *  3. The approved count is derived on demand, never stored, so it cannot drift.
 *
 * Events are emitted **after** the write succeeds: a listener must never observe a
 * comment that was rolled back.
 */
final class CommentService implements CommentServiceInterface
{
    private const MIN_BODY_LENGTH = 2;
    private const MAX_BODY_LENGTH = 5000;

    public function __construct(
        private readonly CommentRepositoryInterface $comments,
        private readonly ContentServiceInterface $contents,
        private readonly UserServiceInterface $users,
        private readonly EventDispatcherInterface $events,
        private readonly ClockInterface $clock,
    ) {
    }

    public function submit(
        int $siteId,
        int $contentId,
        int $userId,
        string $body,
        ?int $parentId = null,
    ): Comment {
        $body = trim($body);

        if (mb_strlen($body) < self::MIN_BODY_LENGTH) {
            throw new \InvalidArgumentException('A comment is too short.');
        }

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new \InvalidArgumentException('A comment is too long.');
        }

        try {
            $content = $this->contents->get($contentId, $siteId);
        } catch (ContentNotFoundException) {
            throw new CommentNotFoundException();
        }

        // Comments only exist on publicly visible content: a draft's thread would be
        // unreachable and would leak its existence through a 200 response.
        if (!$content->isPubliclyVisible()) {
            throw new CommentNotFoundException();
        }

        if ($content->commentStatus !== 'open') {
            throw new CommentsClosedException();
        }

        $depth = 0;

        if ($parentId !== null) {
            $parent = $this->comments->find($parentId, $siteId);

            // A parent must exist, belong to this content, and be visible. Otherwise a
            // reply could be attached to another article's thread.
            if ($parent === null || $parent->contentId !== $contentId || !$parent->isVisible()) {
                throw new CommentNotFoundException();
            }

            $depth = $parent->depth + 1;

            if ($depth > self::MAX_DEPTH) {
                throw new CommentDepthExceededException();
            }
        }

        $user = $this->users->find($userId, $siteId);

        if ($user === null) {
            throw new CommentNotFoundException();
        }

        $comment = $this->comments->create($siteId, [
            'content_id' => $contentId,
            'parent_id' => $parentId,
            'user_id' => $userId,
            'author_name' => $user->displayName,
            'body' => $body,
            // New comments await moderation. Auto-approving would put unreviewed text
            // on a public page.
            'status' => Comment::STATUS_PENDING,
            'depth' => $depth,
        ]);

        $this->events->dispatch(DomainEvent::create(
            'comment.created',
            [
                'id' => $comment->id,
                'content_id' => $contentId,
                'user_id' => $userId,
                'parent_id' => $parentId,
            ],
            $siteId,
            $this->clock,
        ));

        return $comment;
    }

    public function thread(int $siteId, int $contentId): array
    {
        return $this->comments->approvedForContent($siteId, $contentId);
    }

    public function listForSite(int $siteId, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        return $this->comments->listForSite($siteId, $status, $limit, $offset);
    }

    public function countForSite(int $siteId, ?string $status = null): int
    {
        return $this->comments->countForSite($siteId, $status);
    }

    public function approvedCount(int $siteId, int $contentId): int
    {
        return $this->comments->countApprovedForContent($siteId, $contentId);
    }

    public function approve(int $commentId, int $siteId): Comment
    {
        $comment = $this->require($commentId, $siteId);

        // Approving an already-approved comment must not emit a second event, or a
        // notification listener would fire twice for one decision.
        if ($comment->isApproved()) {
            return $comment;
        }

        $this->comments->updateStatus($commentId, $siteId, Comment::STATUS_APPROVED);

        $updated = $this->require($commentId, $siteId);

        $this->events->dispatch(DomainEvent::create(
            'comment.approved',
            ['id' => $commentId, 'content_id' => $updated->contentId],
            $siteId,
            $this->clock,
        ));

        return $updated;
    }

    public function markSpam(int $commentId, int $siteId): Comment
    {
        $this->require($commentId, $siteId);
        $this->comments->updateStatus($commentId, $siteId, Comment::STATUS_SPAM);

        return $this->require($commentId, $siteId);
    }

    public function trash(int $commentId, int $siteId): Comment
    {
        $this->require($commentId, $siteId);
        $this->comments->updateStatus($commentId, $siteId, Comment::STATUS_TRASH);

        return $this->require($commentId, $siteId);
    }

    public function delete(int $commentId, int $siteId): bool
    {
        $comment = $this->require($commentId, $siteId);

        $deleted = $this->comments->delete($commentId, $siteId);

        if ($deleted) {
            $this->events->dispatch(DomainEvent::create(
                'comment.deleted',
                ['id' => $commentId, 'content_id' => $comment->contentId],
                $siteId,
                $this->clock,
            ));
        }

        return $deleted;
    }

    private function require(int $commentId, int $siteId): Comment
    {
        $comment = $this->comments->find($commentId, $siteId);

        if ($comment === null) {
            throw new CommentNotFoundException();
        }

        return $comment;
    }
}
