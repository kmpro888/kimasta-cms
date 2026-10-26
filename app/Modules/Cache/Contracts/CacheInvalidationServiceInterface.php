<?php

declare(strict_types=1);

namespace CMS\Modules\Cache\Contracts;

/**
 * Event-driven, targeted cache invalidation.
 *
 * The product rule is explicit: do not wipe an entire site cache after every
 * article edit. This service maps a domain event to the dependency it invalidates
 * and evicts only the entries that declared that dependency.
 *
 * FROZEN CONTRACT.
 */
interface CacheInvalidationServiceInterface
{
    /**
     * Handle a domain event name + payload and evict what it affects.
     *
     * @param array<string, mixed> $payload
     *
     * @return int number of page entries evicted
     */
    public function invalidateForEvent(string $eventName, array $payload): int;

    /**
     * Register the listeners that connect domain events to this service.
     */
    public function subscribe(): void;

    /**
     * Evict a single page path for a site.
     */
    public function invalidatePage(int $siteId, string $path): void;

    /**
     * Evict every page depending on a dependency key.
     */
    public function invalidateDependency(int $siteId, string $dependencyKey): int;

    /**
     * Evict an entire site's cache. Recovery only, never the normal path.
     */
    public function invalidateSite(int $siteId): int;

    /**
     * Which dependency key a given event maps to, or null when the event is
     * irrelevant to the cache.
     */
    public function dependencyKeyFor(string $eventName, array $payload): ?string;
}
