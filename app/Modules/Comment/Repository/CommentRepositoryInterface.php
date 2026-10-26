<?php

declare(strict_types=1);

namespace CMS\Modules\Comment\Repository;

use CMS\Modules\Comment\Entity\Comment;

/**
 * `comments` persistence.
 */
interface CommentRepositoryInterface
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(int $siteId, array $attributes): Comment;

    public function find(int $id, int $siteId): ?Comment;

    /**
     * Approved comments for a content row, oldest first.
     *
     * @return list<Comment>
     */
    public function approvedForContent(int $siteId, int $contentId, int $limit = 200): array;

    /**
     * Every comment for a content row, for moderation.
     *
     * @return list<Comment>
     */
    public function allForContent(int $siteId, int $contentId, ?string $status = null, int $limit = 200): array;

    /**
     * Flat moderation queue across the site.
     *
     * @return list<Comment>
     */
    public function listForSite(int $siteId, ?string $status = null, int $limit = 50, int $offset = 0): array;

    public function countForSite(int $siteId, ?string $status = null): int;

    public function countApprovedForContent(int $siteId, int $contentId): int;

    public function updateStatus(int $id, int $siteId, string $status): void;

    public function delete(int $id, int $siteId): bool;
}
