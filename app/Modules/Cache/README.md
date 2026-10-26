# Cache Module — Cache & Performance

Makes the public frontend cache-first and database-light: a second anonymous
request to an article is answered from one file read with **zero database queries**.

## Ownership

`app/Modules/Cache/**`, `storage/cache/**`.

## Contracts provided

| Contract | Implementation |
|---|---|
| `CMS\Shared\Contracts\CacheInterface` (frozen) | `FileCache` |
| `Contracts\PageCacheInterface` | `Page\PageCache` |
| `Contracts\CacheInvalidationServiceInterface` | `Service\CacheInvalidationService` |
| `Contracts\CacheDependencyInterface` | `Entity\CacheDependency` |

## Four layers

| Layer | Where | Purpose |
|---|---|---|
| Request memo | array in `FileCache` | same value twice in one request costs nothing |
| Object cache | `storage/cache/objects` | survives the request, TTL'd |
| Full page cache | `storage/cache/pages/{siteId}` | the zero-query path |
| Generated artifacts | `storage/cache/system` | domain map, sitemap, compiled settings |

## Fast path

```text
request
  → SiteResolver (compiled domain map, 0 queries)
  → PageCache::get()   ← one file read
  → HIT: send HTML, exit
```

## Eligibility

Cached only when **all** of these hold: method `GET`/`HEAD`, anonymous, not a
preview, and a public response. A `POST`, a logged-in page, a preview or a draft is
never served from this cache.

## Targeted invalidation

`CacheInvalidationService::subscribe()` wires domain events to eviction. Two keys do
the work:

| Dependency | Declared by | Invalidated by |
|---|---|---|
| `content:{id}` | the article page | `content.updated/published/deleted/…` |
| `archive:{siteId}` | any listing page | any content change |
| `term:{id}` | a taxonomy archive | `term.updated`/`term.deleted` |
| `theme:{name}` | every rendered page | `theme.activated` |
| `settings:{siteId}` | pages reading settings | `settings.updated` |

A static page that declared nothing is **not** evicted when an article changes. That
is the difference between targeted invalidation and a site-wide wipe, and it has a
test.

The dependency index is read only during invalidation (a rare write path), so the
hot path never pays for it.

## Degradation rules

- A corrupt entry is **deleted and treated as a miss**, never an error.
- A read-only cache directory makes writes no-ops; the request still completes.
- An event without a `site_id` evicts nothing, because guessing the tenant would
  evict the wrong site.

## Not the normal path

`invalidateSite()` exists for recovery only. Nothing in the codebase calls it after
an edit.
