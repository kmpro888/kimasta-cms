<?php

declare(strict_types=1);

namespace CMS\Modules\Taxonomy\Repository;

/**
 * Taxonomy row persistence (`taxonomies` table).
 *
 * The registry knows which taxonomies *exist conceptually*; this repository
 * knows which ones have a row for a given site. Both are needed because a
 * taxonomy registered by a plugin still has to be materialised per tenant.
 */
interface TaxonomyRepositoryInterface
{
    public function findIdByName(int $siteId, string $name, ?string $contentType = null): ?int;

    /**
     * Insert when absent and return the id either way.
     */
    public function ensure(int $siteId, string $name, ?string $contentType, bool $hierarchical): int;

    /**
     * @return array<string, array{id: int, name: string, hierarchical: bool, content_type: string|null}>
     */
    public function listBySite(int $siteId): array;

    public function findHierarchical(int $taxonomyId, int $siteId): bool;
}
