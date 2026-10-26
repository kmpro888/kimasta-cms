# Public Rendering Layer — Implementation Report

Agent: Controller-integrated (fills the gap stated at the end of Wave 6)
Module: `CMS\Modules\Theme\Http`, `CMS\Modules\Theme\Rendering`
Status: DONE

---

## Why this was built before Wave 7

Wave 6 closed with an honest note: **nothing served a themed page over HTTP**. The
Cache, SEO and Theme modules were complete and tested, but the controller that
connects them did not exist, so the work was unreachable.

Building Agent 13 (REST API) on top of a platform that cannot serve its own public
pages would have been the wrong order. This layer is the smallest thing that makes
everything already built observable, and it is also where the cache stampede lock
and the page-dependency declarations actually belong.

---

## Files added

```text
app/Modules/Theme/Http/PublicPageController.php
app/Modules/Theme/Rendering/PageViewModelFactory.php
```

## Files modified

```text
app/Modules/Theme/ThemeServiceProvider.php        controller + factory bound; route registered
app/Modules/Site/Contracts/SiteResolverInterface.php   added siteIdFor()
app/Modules/Site/Service/SiteResolver.php         implemented siteIdFor()
app/Core/Routing/Route.php                        compilePattern() is now brace-aware
tests/Unit/Core/RouterTest.php                    6 regression tests for the routing bug
```

---

## The request path

```text
GET /blog/hello
  ↓
siteIdFor(host)              compiled domain map — 0 queries
  ↓
PageCache::get(siteId, path) one file read
  ↓ HIT: send HTML, X-Cache: HIT, exit        ← 0 queries total
  ↓ MISS
resolveById(siteId)          full SiteContext, only now
RedirectService::resolve()   a moved permalink outranks a 404
ContentQueryService::findByUrl()   2 queries
  ↓ not publicly visible → themed 404
PageViewModelFactory         content + SEO + breadcrumbs + terms + featured media
TemplateResolver             single-{type} → single → index
ViewRenderer                 isolated scope, only $view and $renderer
  ↓
PageCache::put() with dependencies declared
  ↓
send HTML, X-Cache: MISS
```

### Why `siteIdFor()` was added

The original fast path resolved the full `SiteContext` before touching the cache,
which meant one `SELECT` on the sites table even on a hit. That contradicted the
"0 DB queries" target.

`SiteResolverInterface::siteIdFor(string $host): int` answers from the compiled
domain map with no database. The full context is now built only on a miss. This is
an **additive** change to a module contract, not a change to a frozen one.

---

## Dependencies a cached page declares

```text
content:{id}      the article itself
archive:{siteId}  every listing that would show it
theme:{name}      the theme that rendered it
settings:{siteId} site settings it read
author:{id}       the author archive
media:{id}        the featured image, when present
```

Omitting any of these would leave a stale page after the matching edit, so they are
listed explicitly in one method and asserted by a test that reads the dependency
index back.

---

## Verified over real Apache HTTP

Not just in tests — this is the actual output from `http://line.id`:

| Request | Result |
|---|---|
| `GET /blog/hello-from-the-public-renderer` (1st) | `200`, `X-Cache: MISS`, `Content-Type: text/html; charset=utf-8` |
| same (2nd) | `200`, `X-Cache: HIT`, body byte-identical |
| `GET /about-this-demo` | `200`, `WebPage` JSON-LD, breadcrumbs |
| `GET /old-home` | `301`, `Location: /blog/hello-from-the-public-renderer` |
| `GET /does-not-exist` | `404`, themed page, `X-Cache: BYPASS` |
| `GET /api/v1/health` | `200`, `{"data":{"status":"ok"},"meta":{}}` |
| `GET /api/v1/does-not-exist` | `404`, frozen JSON error envelope |

The rendered article contained the title, canonical link, robots meta, JSON-LD,
both block bodies (heading + paragraph + list) and the attached term.

---

## Bug found in Core routing

Way 6's route did not match at all, and the cause was in Agent 01's code:

```php
// Route::compilePattern() before
$segments = explode('/', trim($this->path, '/'));
```

`explode('/')` splits **inside** `{...}` groups. A constraint containing a slash —
`{path:(?!api/|admin/).*}` — was shredded into three literal segments
(`{path:(?!api`, `|admin`, `).*}`), producing a pattern that could never match.

Fixed with a brace-aware `splitSegments()`. Six regression tests now cover:

- a constraint containing slashes matches
- the same constraint still excludes `api/` and `admin/`
- a catch-all matches the root path with an empty capture
- ordinary segment routes are unaffected
- an explicit `/api/...` route wins over a later catch-all
- an unknown `/api/...` path still falls through to the frozen error envelope

This bug would have silently disabled every future regex-constrained route, so it
was worth fixing in Core rather than working around it.

---

## Security and correctness decisions

| Decision | Reason |
|---|---|
| Unknown host → plain 404, never a default tenant | a DNS typo must not serve another site |
| Draft / non-public content → 404 | the page cache must not become a leak channel |
| Redirect resolved before content | a moved permalink outranks a 404 |
| A failed render is **not** cached | caching a broken page would freeze it until the next edit |
| `preview=1` bypasses the cache | an editor must never see a stale anonymous page |
| `authenticated` / `preview` attributes considered by `isEligible()` | a future caller cannot accidentally cache a private page |
| `X-Cache: HIT/MISS/BYPASS` on every response | makes cache behaviour observable in production logs |
| `/api/` and `/admin/` excluded from the theme route | the API's frozen JSON envelope must not be replaced by themed HTML |

---

## Tests added

- `tests/Integration/Modules/Theme/PublicPageControllerTest.php` — 15 tests
  - render, SEO tags, MISS→HIT, **0-query HIT**, dependency declaration,
    edit-then-invalidate round trip, 404, draft, unknown host, redirect before
    404, cross-site isolation, HEAD, preview bypass, terms rendering
- `tests/Unit/Core/RouterTest.php` — 6 new regression tests for the routing bug

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (825 tests, 2684 assertions)
```

Baseline 804 → 825: **+21 tests**, none broken.

| Suite | Tests | Assertions |
|---|---|---|
| Unit | 328 | 795 |
| Integration | 329 | 705 |
| Contract | 58 | 780 |
| Security | 103 | 389 |
| Performance | 7 | 15 |
| **Total** | **825** | **2684** |

---

## What this unblocks

- Wave 6's cache invalidation now has real consumers: the pages it was designed to
  evict are the pages this controller writes.
- The cache stampede lock now has an obvious home: wrapped around the MISS branch.
- The sitemap rebuild job can reuse this same ViewModel factory for future URLs.
- Agent 13 (REST API) builds on a platform that demonstrably serves pages.

## Known limitations

1. **Archive, taxonomy, author and search pages still 404.** Only `single-{type}` and
   `page` resolve. The listing ViewModel (`forListing`) exists and is tested, but
   no route maps `/blog` or `/term/{slug}` to it yet.
2. **No cache stampede lock on the MISS path.** `PageCache::locksDirectory()` is
   reserved; acquiring around the render is the next small step.
3. `page_cache` stores the rendered HTML only; no ETag/If-None-Match handling, so a
   revalidating client still gets a full body.
4. The `authenticated` flag is taken from a request attribute, because the session is
   deliberately not started on the cache-first path. When the admin layer starts
   sessions it must set that attribute.
5. Demo data was seeded into the development database (`Live Demo` site on
   `line.id`) purely to verify over HTTP. It is not part of the test suite and can be
   deleted with `DELETE FROM sites WHERE primary_domain = 'line.id'`.

---

## CONTRACT_CHANGE_REQUEST

none

`SiteResolverInterface` gained `siteIdFor()` — an additive method on a module contract
created in Wave 2, not a change to any contract frozen by `AGENTS.md`.

---

## Ready to merge

yes
