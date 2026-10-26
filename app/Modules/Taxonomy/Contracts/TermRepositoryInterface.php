<?php

declare(strict_types=1);

namespace CMS\Modules\Taxonomy\Contracts;

use CMS\Modules\Taxonomy\Entity\Term;

/**
 * Term persistence.
 *
 * FROZEN CONTRACT.
 */
interface TermRepositoryInterface
{
    public function findById(int $termId, int $siteId): ?Term;

    public function findBySlug(string $slug, int $taxonomyId, int $siteId): ?Term;

    /**
     * @return list<Term>
     */
    public function listByTaxonomy(int $taxonomyId, int $siteId, ?int $parentId = null): array;

    /**
     * Every term of a taxonomy regardless of depth.
     *
     * @return list<Term>
     */
    public function listAllInTaxonomy(int $taxonomyId, int $siteId): array;

    /**
     * Resolve many terms in ONE query, so a batch loader never becomes N+1.
     *
     * @param list<int> $termIds
     *
     * @return array<int, Term> keyed by term id
     */
    public function findByIds(int $siteId, array $termIds): array;

    /**
     * Resolve a term by slug without knowing which taxonomy it belongs to.
     *
     * Used by the public `/term/{slug}` archive, where the URL carries no taxonomy.
     */
    public function findBySlugAcrossTaxonomies(string $slug, int $siteId): ?Term;

    /**
     * Content ids attached to a term, for a taxonomy archive listing.
     *
     * @return list<int>
     */
    public function contentIdsForTerm(int $termId, int $siteId): array;

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(int $siteId, array $attributes): Term;

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(int $termId, int $siteId, array $attributes): void;

    public function delete(int $termId, int $siteId): bool;

    public function slugExists(string $slug, int $taxonomyId, int $siteId, ?int $exceptId = null): bool;

    /**
     * Attach with counter reconciliation in one statement pair.
     */
    public function attach(int $contentId, int $termId, int $siteId): bool;

    public function detach(int $contentId, int $termId, int $siteId): bool;

    /**
     * @return list<int>
     */
    public function termIdsForContent(int $contentId, int $siteId): array;

    /**
     * @param list<int> $contentIds
     *
     * @return array<int, list<int>> content id => term ids
     */
    public function termIdsForContents(int $siteId, array $contentIds): array;

    public function adjustCount(int $termId, int $siteId, int $delta): void;

    public function countByTaxonomy(int $taxonomyId, int $siteId): int;
}
