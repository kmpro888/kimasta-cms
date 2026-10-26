<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Repository;

/**
 * URL registry persistence.
 *
 * The registry maps a public path to exactly one entity per site. Its primary
 * key (site_id, path) is what makes permalink resolution a single indexed
 * lookup and makes collision impossible at the storage layer.
 */
interface UrlRegistryRepositoryInterface
{
    /**
     * Insert or move the mapping for an entity.
     */
    public function put(int $siteId, string $path, string $entityType, int $entityId): void;

    /**
     * Remove every mapping for an entity.
     */
    public function forget(int $siteId, string $entityType, int $entityId): void;

    /**
     * @return array{entity_type: string, entity_id: int}|null
     */
    public function resolve(int $siteId, string $path): ?array;

    /**
     * Is the path owned by some other entity?
     */
    public function isTakenByAnother(int $siteId, string $path, string $entityType, int $entityId): bool;

    /**
     * Canonical path currently registered for an entity, if any.
     */
    public function pathFor(int $siteId, string $entityType, int $entityId): ?string;

    /**
     * @return array<string, array{entity_type: string, entity_id: int}>
     */
    public function allForSite(int $siteId): array;
}
