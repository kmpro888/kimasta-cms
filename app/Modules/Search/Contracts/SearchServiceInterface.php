<?php

declare(strict_types=1);

namespace CMS\Modules\Search\Contracts;

/**
 * Content search.
 *
 * The platform ships no search engine: FULLTEXT on MySQL/MariaDB is the primary
 * strategy, and an indexed fallback keeps the product working where FULLTEXT is
 * unavailable. Elasticsearch is explicitly out of scope.
 *
 * FROZEN CONTRACT.
 */
interface SearchServiceInterface
{
    /**
     * Search published content.
     *
     * @param array{
     *     limit?: int,
     *     offset?: int,
     *     type?: string,
     *     include_unpublished?: bool
     * } $options
     *
     * @return array{
     *     items: list<\CMS\Modules\Content\Entity\ContentSummary>,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     total_pages: int,
     *     strategy: string
     * }
     */
    public function search(int $siteId, string $term, array $options = []): array;

    /**
     * Which strategy would be used right now: `fulltext` or `like`.
     *
     * Exposed so the admin can report why search is slow on a given host, instead of
     * the operator having to guess.
     */
    public function strategy(): string;

    /**
     * Does this database support the indexed strategy?
     */
    public function supportsFulltext(): bool;
}
