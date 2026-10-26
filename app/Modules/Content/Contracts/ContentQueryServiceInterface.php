<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Contracts;

/**
 * Read-side content queries for public pages.
 *
 * Deliberately separate from ContentServiceInterface: the write path and the
 * read path have different performance constraints and different callers. A
 * listing must never load full block JSON just to render a card.
 *
 * FROZEN CONTRACT.
 */
interface ContentQueryServiceInterface
{
    /**
     * Paginated listing. Returns summaries, not full content rows.
     *
     * Supported criteria: type, status, author_id, limit, offset, order.
     *
     * @param array<string, mixed> $criteria
     *
     * @return array{items: list<\CMS\Modules\Content\Entity\ContentSummary>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function paginate(int $siteId, array $criteria = []): array;

    /**
     * Published entries for a public archive, newest first.
     *
     * @return list<\CMS\Modules\Content\Entity\ContentSummary>
     */
    public function published(int $siteId, string $type = 'post', int $limit = 20, int $offset = 0): array;

    /**
     * Single content row with every column needed to render, in one query.
     *
     * @throws \CMS\Modules\Content\Exceptions\ContentNotFoundException
     */
    public function findByUrl(int $siteId, string $path): \CMS\Modules\Content\Entity\Content;
}
