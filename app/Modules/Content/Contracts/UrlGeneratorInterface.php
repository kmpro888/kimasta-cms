<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Contracts;

/**
 * Builds the public path for an entity and maintains the URL registry.
 *
 * The registry is the single source of truth for "which entity lives at this
 * path", which is what lets one request resolve a permalink in a single indexed
 * lookup instead of guessing across many tables.
 *
 * FROZEN CONTRACT.
 */
interface UrlGeneratorInterface
{
    /**
     * Public path for a content row, e.g. `/blog/hello-world`.
     */
    public function pathFor(string $type, string $slug): string;

    /**
     * Register or move the canonical path for an entity.
     *
     * @throws \CMS\Modules\Content\Exceptions\UrlCollisionException
     */
    public function register(int $siteId, string $path, string $entityType, int $entityId): void;

    /**
     * Remove every path mapping for an entity (used on purge).
     */
    public function forget(int $siteId, string $entityType, int $entityId): void;

    /**
     * Resolve a path to its entity.
     *
     * @return array{entity_type: string, entity_id: int}|null
     */
    public function resolve(int $siteId, string $path): ?array;

    /**
     * Is this path already owned by a different entity?
     */
    public function isPathTaken(int $siteId, string $path, string $entityType, int $entityId): bool;
}
