# Listing Pages — Implementation Report

Agent: Controller-integrated (closes the largest gap left by the Public Rendering
Layer)
Module: `CMS\Modules\Theme\Http`, plus small additions to Content and Taxonomy
Status: DONE

---

## Why this came next

The Public Rendering Layer shipped with a stated limitation: only
`single-{type}` and `page` resolved, so `/`, `/blog`, `/term/{slug}`,
`/author/{id}` and `/search` all returned 404. The listing ViewModel existed and
was tested, but nothing routed to it.

That made the site unusable as a site: an article was reachable, its homepage and
archives were not. Closing it was worth more than starting the REST API.

---

## Files added

```text
(none — the work extends existing classes)
```

## Files modified

```text
app/Modules/Theme/Http/PublicPageController.php        listing resolution + cache-key fix
app/Modules/Theme/ThemeServiceProvider.php             two extra constructor dependencies
app/Modules/Content/Repository/PdoContentRepository.php  `ids` and `search` criteria
app/Modules/Taxonomy/Contracts/TaxonomyServiceInterface.php   findTermBySlug, contentIdsForTerm
app/Modules/Taxonomy/Contracts/TermRepositoryInterface.php    reverse lookup + content ids
app/Modules/Taxonomy/Repository/PdoTermRepository.php         implementations
app/Modules/Taxonomy/Service/TaxonomyService.php              delegations
tests/Integration/Modules/Theme/PublicPageControllerTest.php  14 new listing tests
```

---

## What resolves now

| Path | Kind | Template context |
|---|---|---|
| `/` | home listing | `home` → `home.php` → `index.php` |
| `/blog` | post archive | `archive-post` → `archive.php` → `index.php` |
| `/{type}s` | archive for a registered type | `archive-{type}` → … |
| `/term/{slug}` | taxonomy archive | `taxonomy` → `archive.php` → `index.php` |
| `/author/{id}` | author archive | `author` → `archive.php` → `index.php` |
| `/search?q=…` | search results | `search` → `archive.php` → `index.php` |
| anything else | themed 404 | `404` → `index.php` |

---

## Data flow for a taxonomy archive

Editorial and Content are not asked to know about terms, and Taxonomy is not asked
to know about content pagination. The controller joins them through their
published contracts:

```text
/term/featured
  → TaxonomyService::findTermBySlug(siteId, 'featured')   one indexed lookup
  → TaxonomyService::contentIdsForTerm(termId, siteId)     one indexed lookup
  → ContentQueryService::paginate(siteId, ['ids' => [...]])  the listing itself
```

Content's repository gained an `ids` criterion for exactly this: an empty id set
emits `1 = 0` so an empty term returns nothing rather than *everything*, which is
the failure mode that matters.

Search uses a `search` criterion matching `title` and `content_plain`. Searching
the plain column is why `content_plain` exists: no HTML stripping at query time.
The term is escaped for `%` and `_` so a user cannot turn a search into a wildcard
scan.

---

## Bug found and fixed: paginated pages served page 1

The page cache keys on the **path only**, and `normalizePath()` strips the query
string. So `/blog?page=2` hit the entry stored by `/blog` and returned page 1.

That is a correctness bug, not a cache-efficiency nit: pagination would have been
silently broken for every cached listing.

Fix: a request whose query changes the representation bypasses the cache entirely.

```php
private function hasVaryingQuery(Request $request): bool
{
    $page = $request->queryParam('page');
    if ($page !== null && (int) $page > 1) return true;

    $query = $request->queryParam('q');
    return is_string($query) && trim($query) !== '';
}
```

Tracking parameters such as `utm_*` deliberately do **not** qualify, so a shared
link still hits the cache. The test that caught this asserts `X-Cache: BYPASS` for
`?page=2`.

---

## Cache behaviour for listings

Listing pages declare three dependencies:

```text
archive:{siteId}   any content change refreshes every listing
theme:{name}       a theme switch evicts them
settings:{siteId}  a settings change evicts them
```

Which is why the invalidation work from Wave 6 now has real consumers on both
surfaces: the article page (`content:{id}`) and the listings (`archive:{siteId}`).

---

## Verified over real Apache HTTP

```
http://line.id/                       200  X-Cache=HIT     contains article
http://line.id/blog                   200  X-Cache=HIT     contains article
http://line.id/term/announcement      200  X-Cache=HIT     contains article
http://line.id/author/2               200  X-Cache=HIT     contains article
http://line.id/search?q=renderer      200  X-Cache=BYPASS  contains article
http://line.id/search                 404  X-Cache=BYPASS
http://line.id/nothing-here           404  X-Cache=BYPASS
```

The home page renders `<title>Live Demo</title>`, `<h1>Live Demo</h1>` and the
article cards.

---

## Tests added

14 new tests in `PublicPageControllerTest` (now 29 total):

- home listing renders
- type archive renders under its slug
- home listing cached, and declares `archive:{siteId}`
- a content event invalidates the cached listing
- taxonomy archive lists the term's content
- an unknown term is a 404
- a taxonomy archive excludes content from another term
- author archive lists that author's content
- search finds content by title
- search is never cached and two queries do not share a result
- an empty search query is a 404
- a deeper listing page bypasses the cache (**the pagination bug**)
- a draft is excluded from listings
- an unregistered archive segment is a 404

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (839 tests, 2726 assertions)
```

Baseline 825 → 839: **+14 tests**, none broken.

---

## Known limitations

1. **Pagination is not cache-optimised.** Page 2+ always renders fresh. Correct, but
   an archive with heavy traffic on deep pages gains nothing from the cache. A cache
   key that includes the page number would fix it and needs a `PageCacheInterface`
   change.
2. **No pagination links in the default theme.** `archive.php` prints "Page X of Y"
   but not previous/next URLs, because the ViewModel does not carry the base path
   plus query builder. Cosmetic, and the data is present.
3. **Author archive title is the literal string "Author"**, not the author's display
   name. Resolving the name needs an Identity lookup the listing factory does not
   perform.
4. **Taxonomy archive does not paginate the term's full set efficiently.** It
   resolves every content id for the term, then paginates. Fine for hundreds of
   rows; a join through `content_terms` would be the next step for thousands.
5. **Search is unranked** — title and body matches are treated equally and ordered by
   publish date. No relevance scoring, which is consistent with not introducing a
   search engine.
6. `/{type}s` pluralisation is a guess (`product` → `/products`). A type whose
   plural is irregular needs an explicit archive slug in its registration.

---

## CONTRACT_CHANGE_REQUEST

none

`TaxonomyServiceInterface`, `TermRepositoryInterface` and `SiteResolverInterface`
each gained additive methods on module contracts created in earlier waves. No
contract frozen by `AGENTS.md` was touched.

---

## Ready to merge

yes
