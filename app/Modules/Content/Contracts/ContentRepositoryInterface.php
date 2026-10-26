<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Contracts;

/**
 * Content persistence.
 *
 * Every method is site-scoped. A content id belonging to another site must
 * resolve to null and never to the foreign row.
 *
 * This interface is one of the frozen contracts: later modules (workflow,
 * revisions, SEO, cache, API) code against it, so it is defined once here and
 * must not be widened casually.
 */
interface ContentRepositoryInterface
{
    public function findById(int $id, int $siteId): ?\CMS\Modules\Content\Entity\Content;

    /**
     * Resolve by site + type + slug. Uses the unique index
     * (site_id, type, slug).
     */
    public function findBySlug(string $slug, string $type, int $siteId): ?\CMS\Modules\Content\Entity\Content;

    /**
     * Insert a new content row for a site and return it.
     *
     * The site id is an explicit parameter rather than a key inside
     * `$attributes`: a tenant boundary that lives inside a free-form array is a
     * boundary a caller can forget.
     *
     * @param array<string, mixed> $attributes
     */
    public function create(int $siteId, array $attributes): \CMS\Modules\Content\Entity\Content;

    /**
     * Persist the mutable columns of an existing row.
     *
     * @param array<string, mixed> $attributes
     */
    public function update(int $id, int $siteId, array $attributes): void;

    /**
     * Move a row to the trash status.
     */
    public function trash(int $id, int $siteId, \DateTimeImmutable $at): bool;

    /**
     * Permanently delete a row.
     */
    public function purge(int $id, int $siteId): bool;

    /**
     * Does this slug already exist for the type on this site?
     */
    public function slugExists(string $slug, string $type, int $siteId, ?int $exceptId = null): bool;

    /**
     * Listing query that selects only the columns a listing needs.
     *
     * @param array<string, mixed> $criteria
     *
     * @return list<\CMS\Modules\Content\Entity\ContentSummary>
     */
    public function paginate(int $siteId, array $criteria = [], int $limit = 20, int $offset = 0): array;

    /**
     * Total rows matching the same criteria, for pagination metadata.
     *
     * @param array<string, mixed> $criteria
     */
    public function count(int $siteId, array $criteria = []): int;
}
