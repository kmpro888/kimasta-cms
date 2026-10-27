<?php

declare(strict_types=1);

namespace CMS\Modules\Comment\Contracts;

use CMS\Modules\Comment\Entity\Comment;

/**
 * Comment threads.
 *
 * Submission requires an authenticated user: the task pack is explicit, and it
 * removes anonymous spam and the need for a captcha in the core.
 *
 * Reply depth is bounded at write time, so rendering never needs recursion and a
 * thread can always be paginated.
 *
 * FROZEN CONTRACT.
 */
interface CommentServiceInterface
{
    /** Maximum reply depth. A depth of 0 is a top-level comment. */
    public const MAX_DEPTH = 3;

    /**
     * Submit a comment.
     *
     * @throws \CMS\Modules\Comment\Exceptions\CommentsClosedException
     * @throws \CMS\Modules\Comment\Exceptions\CommentDepthExceededException
     * @throws \CMS\Modules\Comment\Exceptions\CommentNotFoundException
     */
    public function submit(
        int $siteId,
        int $contentId,
        int $userId,
        string $body,
        ?int $parentId = null,
        ?string $authorName = null,
    ): Comment;

    /**
     * Approved thread as a flat, depth-tagged list in display order.
     *
     * @return list<Comment>
     */
    public function thread(int $siteId, int $contentId): array;

    /**
     * Moderation queue, newest first.
     *
     * @return list<Comment>
     */
    public function listForSite(int $siteId, ?string $status = null, int $limit = 50, int $offset = 0): array;

    public function countForSite(int $siteId, ?string $status = null): int;

    /**
     * Approved comment count for one content row.
     */
    public function approvedCount(int $siteId, int $contentId): int;

    /**
     * @throws \CMS\Modules\Comment\Exceptions\CommentNotFoundException
     */
    public function approve(int $commentId, int $siteId): Comment;

    /**
     * @throws \CMS\Modules\Comment\Exceptions\CommentNotFoundException
     */
    public function markSpam(int $commentId, int $siteId): Comment;

    /**
     * @throws \CMS\Modules\Comment\Exceptions\CommentNotFoundException
     */
    public function trash(int $commentId, int $siteId): Comment;

    /**
     * @throws \CMS\Modules\Comment\Exceptions\CommentNotFoundException
     */
    public function delete(int $commentId, int $siteId): bool;
}
