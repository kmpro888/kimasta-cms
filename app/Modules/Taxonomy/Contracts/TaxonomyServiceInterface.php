<?php

declare(strict_types=1);

namespace CMS\Modules\Taxonomy\Contracts;

use CMS\Modules\Taxonomy\Entity\Term;

/**
 * Taxonomy and term application service.
 *
 * Every method is site-scoped. Content association is the only supported way to
 * change what a term points at, and it keeps `terms.content_count` accurate in
 * the same operation.
 *
 * FROZEN CONTRACT.
 */
interface TaxonomyServiceInterface
{
    /**
     * Ensure a registered taxonomy exists as a row for a site.
     *
     * @throws \CMS\Modules\Taxonomy\Exceptions\UnknownTaxonomyException
     */
    public function ensureTaxonomy(int $siteId, string $name): int;

    /**
     * Seed the default taxonomies (category, tag) for a site.
     */
    public function seedDefaults(int $siteId): void;

    /**
     * @return list<Term>
     */
    public function listTerms(int $siteId, string $taxonomy, ?int $parentId = null): array;

    public function getTerm(int $termId, int $siteId): Term;

    /**
     * @throws \CMS\Modules\Taxonomy\Exceptions\DuplicateTermException
     */
    public function createTerm(int $siteId, string $taxonomy, string $name, ?string $description = null, ?int $parentId = null, ?string $slug = null): Term;

    /**
     * @throws \CMS\Modules\Taxonomy\Exceptions\TermNotFoundException
     */
    public function updateTerm(int $termId, int $siteId, array $attributes): Term;

    /**
     * Deleting a term detaches it from content but never deletes content.
     *
     * @throws \CMS\Modules\Taxonomy\Exceptions\TermNotFoundException
     */
    public function deleteTerm(int $termId, int $siteId): bool;

    /**
     * Attach a term to content. Idempotent, and adjusts content_count once.
     */
    public function attach(int $contentId, int $termId, int $siteId): void;

    public function detach(int $contentId, int $termId, int $siteId): void;

    /**
     * Replace the full term set of a content row and reconcile the counters.
     *
     * @param list<int> $termIds
     */
    public function sync(int $contentId, int $siteId, array $termIds): void;

    /**
     * Terms for many content rows in ONE query, so an archive never issues a
     * query per post.
     *
     * @param list<int> $contentIds
     *
     * @return array<int, list<Term>> keyed by content id
     */
    public function termsForContents(int $siteId, array $contentIds): array;

    /**
     * Resolve a term by slug without knowing its taxonomy.
     *
     * The public archive URL `/term/{slug}` carries no taxonomy, so the lookup has
     * to work across them.
     */
    public function findTermBySlug(int $siteId, string $slug): ?Term;

    /**
     * Content ids attached to a term, for building a taxonomy archive.
     *
     * @return list<int>
     */
    public function contentIdsForTerm(int $termId, int $siteId): array;
}
