<?php

declare(strict_types=1);

namespace CMS\Modules\Cache\Contracts;

use CMS\Modules\Cache\Entity\CacheDependency;
use CMS\Modules\Cache\Entity\CachedPage;

/**
 * Full-page cache for anonymous public responses.
 *
 * This is the layer that makes the product's core promise possible: a second
 * anonymous request to an article is answered from one file read, with **zero
 * database queries**.
 *
 * Everything is site-namespaced. A cache entry is never readable across tenants,
 * because the site id is part of both the key and the directory.
 *
 * FROZEN CONTRACT.
 */
interface PageCacheInterface
{
    /**
     * Read a cached page. Returns null on miss, expiry, or an unreadable entry.
     */
    public function get(int $siteId, string $path): ?CachedPage;

    /**
     * Store a rendered page together with the dependencies it declared.
     *
     * @param list<CacheDependency> $dependencies
     * @param int|null $ttl Seconds; null means "until invalidated".
     */
    public function put(int $siteId, string $path, string $html, array $dependencies = [], ?int $ttl = null): void;

    public function forget(int $siteId, string $path): void;

    /**
     * Evict every page that declared this dependency. Returns the number removed.
     */
    public function forgetByDependency(int $siteId, string $dependencyKey): int;

    /**
     * Evict everything for one tenant. Used only for explicit recovery.
     */
    public function flushSite(int $siteId): int;

    /**
     * Whether this request is even eligible to be served from the page cache.
     */
    public function isEligible(string $method, bool $authenticated, bool $isPreview, bool $isPublic): bool;

    /**
     * Cache key for a path, exposed so logs and tests can reason about it.
     */
    public function keyFor(int $siteId, string $path): string;
}
