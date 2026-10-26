<?php

declare(strict_types=1);

namespace CMS\Modules\Taxonomy\Service;

use CMS\Core\Events\DomainEvent;
use CMS\Modules\Taxonomy\Contracts\TaxonomyRegistryInterface;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use CMS\Modules\Taxonomy\Contracts\TermRepositoryInterface;
use CMS\Modules\Taxonomy\Entity\Term;
use CMS\Modules\Taxonomy\Exceptions\DuplicateTermException;
use CMS\Modules\Taxonomy\Exceptions\TermNotFoundException;
use CMS\Modules\Taxonomy\Repository\TaxonomyRepositoryInterface;
use CMS\Modules\Taxonomy\Support\TermSlug;
use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;
use InvalidArgumentException;
use PDOException;

/**
 * Taxonomy and term application service.
 *
 * Two responsibilities that must stay together:
 *
 *  1. Match the registry (what a taxonomy *is*) with the per-site row (what a
 *     tenant actually has).
 *  2. Keep `terms.content_count` exact across attach, detach and sync, because an
 *     archive page reads that counter instead of running COUNT(*).
 */
final class TaxonomyService implements TaxonomyServiceInterface
{
    public function __construct(
        private readonly TaxonomyRegistryInterface $registry,
        private readonly TaxonomyRepositoryInterface $taxonomies,
        private readonly TermRepositoryInterface $terms,
        private readonly EventDispatcherInterface $events,
        private readonly ClockInterface $clock,
    ) {
    }

    public function ensureTaxonomy(int $siteId, string $name): int
    {
        $definition = $this->registry->get($name);

        return $this->taxonomies->ensure(
            $siteId,
            $name,
            $definition['content_type'],
            $definition['hierarchical']
        );
    }

    public function seedDefaults(int $siteId): void
    {
        if ($siteId <= 0) {
            throw new InvalidArgumentException('Cannot seed taxonomies for an invalid site id.');
        }

        foreach ($this->registry->names() as $name) {
            $this->ensureTaxonomy($siteId, $name);
        }
    }

    public function listTerms(int $siteId, string $taxonomy, ?int $parentId = null): array
    {
        $taxonomyId = $this->ensureTaxonomy($siteId, $taxonomy);

        // A non-hierarchical taxonomy has no parent level, so returning only
        // roots would hide every tag. Flat taxonomies always return everything.
        if (!$this->registry->get($taxonomy)['hierarchical']) {
            return $this->allTermsInTaxonomy($taxonomyId, $siteId);
        }

        return $this->terms->listByTaxonomy($taxonomyId, $siteId, $parentId);
    }

    public function getTerm(int $termId, int $siteId): Term
    {
        $term = $termId > 0 ? $this->terms->findById($termId, $siteId) : null;

        if ($term === null) {
            throw new TermNotFoundException();
        }

        return $term;
    }

    public function createTerm(
        int $siteId,
        string $taxonomy,
        string $name,
        ?string $description = null,
        ?int $parentId = null,
        ?string $slug = null,
    ): Term {
        $definition = $this->registry->get($taxonomy);
        $taxonomyId = $this->ensureTaxonomy($siteId, $taxonomy);

        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A term name is required.');
        }

        // Explicit vs derived slug are intentionally different:
        //  - derived from the name: a collision is auto-suffixed, because the
        //    author did not ask for that exact URL;
        //  - supplied explicitly: a collision is an error, because silently
        //    changing a URL the caller asked for is surprising.
        if ($slug !== null && trim($slug) !== '') {
            $resolved = TermSlug::make($slug);

            if ($this->terms->slugExists($resolved, $taxonomyId, $siteId)) {
                throw new DuplicateTermException();
            }
        } else {
            $resolved = $this->uniqueSlug(TermSlug::make($name), $taxonomyId, $siteId);
        }

        if ($parentId !== null) {
            if (!$definition['hierarchical']) {
                throw new InvalidArgumentException('That taxonomy is flat and cannot have parents.');
            }

            // The parent must belong to the same tenant and taxonomy.
            $parent = $this->terms->findById($parentId, $siteId);

            if ($parent === null || $parent->taxonomyId !== $taxonomyId) {
                throw new TermNotFoundException('Parent term not found.');
            }
        }

        try {
            $term = $this->terms->create($siteId, [
                'taxonomy_id' => $taxonomyId,
                'parent_id' => $parentId,
                'name' => $name,
                'slug' => $resolved,
                'description' => $description,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new DuplicateTermException();
            }

            throw $exception;
        }

        $this->events->dispatch(DomainEvent::create(
            'term.created',
            $this->payload($term, $taxonomy),
            $siteId,
            $this->clock,
        ));

        return $term;
    }

    public function updateTerm(int $termId, int $siteId, array $attributes): Term
    {
        $existing = $this->terms->findById($termId, $siteId);

        if ($existing === null) {
            throw new TermNotFoundException();
        }

        $changes = [];

        if (array_key_exists('name', $attributes)) {
            $name = is_string($attributes['name']) ? trim($attributes['name']) : '';

            if ($name === '') {
                throw new InvalidArgumentException('A term name is required.');
            }

            $changes['name'] = $name;
        }

        if (array_key_exists('description', $attributes)) {
            $changes['description'] = is_string($attributes['description'])
                ? trim($attributes['description'])
                : null;
        }

        if (array_key_exists('slug', $attributes) && is_string($attributes['slug']) && trim($attributes['slug']) !== '') {
            $changes['slug'] = $this->uniqueSlug(
                TermSlug::make($attributes['slug']),
                $existing->taxonomyId,
                $siteId,
                $termId
            );
        }

        if (array_key_exists('parent_id', $attributes)) {
            $parentId = $attributes['parent_id'] === null ? null : (int) $attributes['parent_id'];

            if ($parentId === $termId) {
                throw new InvalidArgumentException('A term cannot be its own parent.');
            }

            if ($parentId !== null) {
                $parent = $this->terms->findById($parentId, $siteId);

                if ($parent === null || $parent->taxonomyId !== $existing->taxonomyId) {
                    throw new TermNotFoundException('Parent term not found.');
                }
            }

            $changes['parent_id'] = $parentId;
        }

        if ($changes === []) {
            return $existing;
        }

        $this->terms->update($termId, $siteId, $changes);

        $updated = $this->terms->findById($termId, $siteId);

        if ($updated === null) {
            throw new TermNotFoundException();
        }

        $this->events->dispatch(DomainEvent::create(
            'term.updated',
            $this->payload($updated, ''),
            $siteId,
            $this->clock,
        ));

        return $updated;
    }

    public function deleteTerm(int $termId, int $siteId): bool
    {
        $term = $this->terms->findById($termId, $siteId);

        if ($term === null) {
            return false;
        }

        // content_terms cascades; contents are untouched by design.
        $deleted = $this->terms->delete($termId, $siteId);

        if ($deleted) {
            $this->events->dispatch(DomainEvent::create(
                'term.deleted',
                $this->payload($term, ''),
                $siteId,
                $this->clock,
            ));
        }

        return $deleted;
    }

    public function attach(int $contentId, int $termId, int $siteId): void
    {
        $term = $this->terms->findById($termId, $siteId);

        if ($term === null) {
            throw new TermNotFoundException();
        }

        // attach() reports whether the association was new, so the counter moves
        // exactly once even if a caller repeats the call.
        $this->terms->attach($contentId, $termId, $siteId);
    }

    public function detach(int $contentId, int $termId, int $siteId): void
    {
        $this->terms->detach($contentId, $termId, $siteId);
    }

    public function sync(int $contentId, int $siteId, array $termIds): void
    {
        $desired = array_values(array_unique(array_map('intval', $termIds)));
        $current = $this->terms->termIdsForContent($contentId, $siteId);

        foreach (array_diff($current, $desired) as $removed) {
            $this->terms->detach($contentId, $removed, $siteId);
        }

        foreach (array_diff($desired, $current) as $added) {
            $term = $this->terms->findById($added, $siteId);

            if ($term === null) {
                throw new TermNotFoundException();
            }

            $this->terms->attach($contentId, $added, $siteId);
        }
    }

    public function termsForContents(int $siteId, array $contentIds): array
    {
        $mapping = $this->terms->termIdsForContents($siteId, $contentIds);

        $allTermIds = [];

        foreach ($mapping as $termIds) {
            foreach ($termIds as $termId) {
                $allTermIds[$termId] = true;
            }
        }

        if ($allTermIds === []) {
            return array_map(static fn (): array => [], $mapping);
        }

        // Two queries for the whole page (associations + terms), regardless of
        // how many posts or terms there are.
        $termRows = $this->terms->findByIds($siteId, array_keys($allTermIds));

        $result = [];

        foreach ($mapping as $contentId => $termIds) {
            $result[$contentId] = array_values(array_filter(array_map(
                static fn (int $termId): ?Term => $termRows[$termId] ?? null,
                $termIds
            )));
        }

        return $result;
    }

    public function findTermBySlug(int $siteId, string $slug): ?Term
    {
        $slug = TermSlug::make($slug);

        return $this->terms->findBySlugAcrossTaxonomies($slug, $siteId);
    }

    public function contentIdsForTerm(int $termId, int $siteId): array
    {
        return $this->terms->contentIdsForTerm($termId, $siteId);
    }

    /**
     * @return list<Term>
     */
    private function allTermsInTaxonomy(int $taxonomyId, int $siteId): array
    {
        return $this->terms->listAllInTaxonomy($taxonomyId, $siteId);
    }

    private function uniqueSlug(string $base, int $taxonomyId, int $siteId, ?int $exceptId = null): string
    {
        if (!$this->terms->slugExists($base, $taxonomyId, $siteId, $exceptId)) {
            return $base;
        }

        $suffix = 2;

        while ($suffix < 1000) {
            $candidate = $base . '-' . $suffix;

            if (!$this->terms->slugExists($candidate, $taxonomyId, $siteId, $exceptId)) {
                return $candidate;
            }

            $suffix++;
        }

        return $base . '-' . bin2hex(random_bytes(4));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Term $term, string $taxonomy): array
    {
        return [
            'id' => $term->id,
            'site_id' => $term->siteId,
            'taxonomy' => $taxonomy,
            'taxonomy_id' => $term->taxonomyId,
            'slug' => $term->slug,
            'name' => $term->name,
            'content_count' => $term->contentCount,
        ];
    }
}
