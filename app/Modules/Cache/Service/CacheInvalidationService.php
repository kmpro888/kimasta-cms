<?php

declare(strict_types=1);

namespace CMS\Modules\Cache\Service;

use CMS\Modules\Cache\Contracts\CacheInvalidationServiceInterface;
use CMS\Modules\Cache\Contracts\PageCacheInterface;
use CMS\Modules\Cache\Entity\CacheDependency;
use CMS\Shared\Contracts\DomainEventInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;

/**
 * Maps domain events to targeted cache eviction.
 *
 * The rule this class exists to enforce: an article edit must not wipe the site
 * cache. Two dependency keys do the work:
 *
 *  - `content:{id}` — the article page itself;
 *  - `archive`      — every listing page (home, category, tag, author archive)
 *    that declared it, because a new or changed post changes those listings too.
 *
 * Listing pages declare `archive` explicitly, so the eviction stays targeted: a
 * contact page or a static about page is untouched.
 */
final class CacheInvalidationService implements CacheInvalidationServiceInterface
{
    /**
     * Dependency type declared by any page whose content is a list of posts.
     */
    public const ARCHIVE_DEPENDENCY_TYPE = 'archive';

    /**
     * Events that affect the article page and every listing.
     */
    private const CONTENT_EVENTS = [
        'content.updated',
        'content.published',
        'content.created',
        'content.deleted',
        'content.archived',
        'content.approved',
    ];

    public function __construct(
        private readonly PageCacheInterface $pages,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    public function subscribe(): void
    {
        foreach (self::CONTENT_EVENTS as $event) {
            $this->events->listen($event, fn (DomainEventInterface $e): int => $this->handle($e), 10);
        }

        foreach (['term.updated', 'term.deleted'] as $event) {
            $this->events->listen($event, fn (DomainEventInterface $e): int => $this->handle($e), 10);
        }

        foreach (['theme.activated', 'settings.updated'] as $event) {
            $this->events->listen($event, fn (DomainEventInterface $e): int => $this->handle($e), 10);
        }
    }

    public function invalidateForEvent(string $eventName, array $payload): int
    {
        $siteId = isset($payload['site_id']) && is_numeric($payload['site_id'])
            ? (int) $payload['site_id']
            : 0;

        if ($siteId <= 0) {
            // Without a tenant we cannot know which namespace to touch, and
            // guessing would evict another site's cache.
            return 0;
        }

        $dependencyKey = $this->dependencyKeyFor($eventName, $payload);

        if ($dependencyKey === null) {
            return 0;
        }

        $evicted = $this->pages->forgetByDependency($siteId, $dependencyKey);

        // A content change also invalidates every listing page.
        if (in_array($eventName, self::CONTENT_EVENTS, true)) {
            $evicted += $this->pages->forgetByDependency(
                $siteId,
                CacheDependency::archive($siteId)->key()
            );
        }

        return $evicted;
    }

    public function invalidatePage(int $siteId, string $path): void
    {
        $this->pages->forget($siteId, $path);
    }

    public function invalidateDependency(int $siteId, string $dependencyKey): int
    {
        return $this->pages->forgetByDependency($siteId, $dependencyKey);
    }

    public function invalidateSite(int $siteId): int
    {
        return $this->pages->flushSite($siteId);
    }

    public function dependencyKeyFor(string $eventName, array $payload): ?string
    {
        if (in_array($eventName, self::CONTENT_EVENTS, true)) {
            $id = $payload['id'] ?? null;

            // `content.deleted` still carries the id, so a trashed or purged page
            // can be evicted without a database read.
            return is_numeric($id) ? CacheDependency::content((int) $id)->key() : null;
        }

        if ($eventName === 'term.updated' || $eventName === 'term.deleted') {
            $id = $payload['term_id'] ?? $payload['id'] ?? null;

            return is_numeric($id) ? CacheDependency::term((int) $id)->key() : null;
        }

        if ($eventName === 'theme.activated') {
            $name = $payload['theme'] ?? null;

            return is_string($name) && $name !== '' ? CacheDependency::theme($name)->key() : null;
        }

        if ($eventName === 'settings.updated') {
            $siteId = $payload['site_id'] ?? null;

            return is_numeric($siteId) ? CacheDependency::settings((int) $siteId)->key() : null;
        }

        return null;
    }

    /**
     * Listener adapter: returns the evicted count so it can be logged upstream.
     */
    private function handle(DomainEventInterface $event): int
    {
        return $this->invalidateForEvent($event->name(), $event->payload());
    }
}
