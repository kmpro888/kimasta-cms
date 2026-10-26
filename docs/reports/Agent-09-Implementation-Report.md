# Agent 09 — Cache & Performance — Implementation Report

Agent: Agent 09 — Cache & Performance
Module: `CMS\Modules\Cache`
Status: DONE

---

## Summary

Implemented the cache-first frontend path: a filesystem object cache, a
site-namespaced full-page cache, and event-driven **targeted** invalidation driven
by an explicit dependency map.

A cached anonymous page is answered by one file read. With the compiled domain map
(Agent 02) that means **zero database queries** for tenant resolution and zero for
the page itself.

Executed by the controller directly; see `Wave-6-Tracking.md`.

---

## Files added

```text
app/Modules/Cache/CacheServiceProvider.php
app/Modules/Cache/README.md
app/Modules/Cache/FileCache.php

app/Modules/Cache/Contracts/PageCacheInterface.php
app/Modules/Cache/Contracts/CacheInvalidationServiceInterface.php
app/Modules/Cache/Contracts/CacheDependencyInterface.php

app/Modules/Cache/Entity/CacheDependency.php
app/Modules/Cache/Entity/CachedPage.php

app/Modules/Cache/Page/PageCache.php
app/Modules/Cache/Service/CacheInvalidationService.php
```

## Files modified

```text
app/bootstrap.php          provider registered LAST (boot() subscribes to events)
docs/reports/Wave-6-Tracking.md
```

---

## Contracts consumed

`EventDispatcherInterface`, `ConfigInterface`, `DomainEventInterface`.

## Contracts provided

`PageCacheInterface`, `CacheInvalidationServiceInterface`, `CacheDependencyInterface`,
and the implementation of Agent 01's frozen `CacheInterface` (`FileCache`).

---

## Four layers

| Layer | Storage | Purpose |
|---|---|---|
| Request memo | in-process array | repeat reads in one request |
| Object cache | `storage/cache/objects` | TTL'd serialized values |
| Full page cache | `storage/cache/pages/{siteId}` | zero-query path |
| Generated artifacts | `storage/cache/system` | domain map, sitemap, settings |

## Fast path

```text
request → SiteResolver (compiled map, 0 queries) → PageCache::get() (1 file read)
        → HIT: send HTML, exit
```

## Eligibility (all required)

`GET`/`HEAD`, anonymous, not preview, public response. A `POST`, a logged-in page, a
preview or a draft is never served from the cache — asserted by a unit test that
enumerates each disqualifier.

---

## Targeted invalidation

Two dependency keys carry the design:

| Dependency | Declared by | Invalidated by |
|---|---|---|
| `content:{id}` | the article page | any `content.*` event |
| `archive:{siteId}` | any listing page | any content change |
| `term:{id}` | taxonomy archive | `term.updated`/`term.deleted` |
| `theme:{name}` | every rendered page | `theme.activated` |
| `settings:{siteId}` | pages reading settings | `settings.updated` |

A static page that declared no dependency is **not** evicted when an article
changes. Without the `archive` key a single edit would either miss the homepage or
require a site-wide wipe; with it, listings refresh and static pages survive.

The dependency index (`deps.php` per site) is read only during invalidation, so the
hot path never pays for it.

---

## Cache impact

This module *is* the cache impact. Measured properties:

| Property | Result |
|---|---|
| Page cache HIT | 1 file read, 0 queries |
| Invalidation after an edit | targeted: article + listings only |
| Static page after an edit | untouched (asserted) |
| Cross-site bleed | impossible: site id in both path and key (asserted) |

## Multisite impact

Every entry lives under `{root}/{siteId}/`. The dependency index is per site. A test
asserts that evicting `content:42` on site 1 leaves site 2's entry for the same
content intact.

An event without a `site_id` evicts **nothing**: guessing the tenant would evict the
wrong site.

---

## Security impact

- A corrupt entry is deleted and treated as a miss, never surfaced as an error.
- Cache directories are created `0775`; writes are atomic (temp + rename) so a
  concurrent reader never sees a partial file.
- Authenticated, preview and non-public responses are excluded, so a personalised
  page cannot leak into the anonymous cache.

---

## Tests added

- unit: `CacheTest` (20 — object cache, page cache, dependency index, eligibility,
  corrupt/expired fallback, site partition)
- integration: `CacheInvalidationTest` (13 — per-event eviction, unrelated page
  survival, cross-site isolation, missing site id, `subscribe()` wiring, 0-query hit)

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (804 tests, 2636 assertions)
```

Cache contributes 33 of those.

---

## Known limitations

1. **No cache stampede lock yet.** The task pack calls for filesystem locks to reduce
   stampede; `PageCache::locksDirectory()` exists and is reserved, but the
   acquire-around-render flow is not wired because rendering lives in the public
   controller layer that a later agent owns. This is the one item from the task pack
   that is **scaffolded rather than implemented**, and it is stated here rather than
   glossed over.
2. No cache size cap or eviction policy beyond TTL. On shared hosting the operator
   prunes `storage/cache`; a size-based sweep is a later concern.
3. `FileCache` stores serialized PHP objects. That is safe here because only the
   application writes the cache, but it must never be pointed at a user-writable
   directory.
4. Page cache TTL defaults to "until invalidated" (`0` in config means no expiry).
   That is correct for a cache that is invalidated by events, and wrong if events
   ever go missing.

---

## CONTRACT_CHANGE_REQUEST

none

Agent 01's `CacheInterface` was implemented unchanged. A contract test asserts the
method surface and arity.

---

## Ready to merge

yes
