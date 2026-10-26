# Wave 6 Tracking — Agent 09 Cache + Agent 10 SEO + Agent 12 Theme

Controller log. Records dispatch, order, defects and validation gates.

## Pre-flight

- Baseline before wave: `686 tests, 2259 assertions` — green.
- Spec read: task pack lines 1177–1327 (09), 1329–1438 (10), 1572–1693 (12);
  schema §27, §35.
- Only Agent 10 needed a migration (`content_seo`, `redirects`). Cache and Theme own
  no tables — theme assignment lives in `sites.active_theme`.

## Ordering decision

The wave contains a real dependency chain, so the three modules were built in
dependency order rather than simultaneously:

```text
1. Agent 09 Cache   (depends on events from 04/05/08)
2. Agent 10 SEO     (depends on Cache's event contract, Content, Media, Identity)
3. Agent 12 Theme   (depends on 09 and 10 for the render path)
```

The provider order in `app/bootstrap.php` now encodes two constraints that would
silently break if reordered:

- **Content must precede Media, Editorial and SEO** — it registers the finders those
  modules consume.
- **Cache must come last** — its `boot()` subscribes to domain events, so every
  emitting module has to be registered first.

Rule G2 (parallel within a wave) again could not be honoured; with one implementer
there is no parallelism to get, and the dependency chain made sequential the correct
choice anyway.

## Gate results

| Gate | Result | Evidence |
|---|---|---|
| G4 full suite green | PASS | `OK (804 tests, 2636 assertions)` |
| No pre-existing test red | PASS | all 686 Wave 1–5 tests still pass |
| Migration forward | PASS | `20261025000900` applied on `line_cms_test` and `line_cms` |
| Migration rollback | PASS | dropped cleanly, re-applied, suite green |
| Frozen contracts untouched | PASS | `CacheInterface` implemented, not changed |
| No `site_id = 1` | PASS | grep clean |
| No cross-module table access | PASS | SEO/Theme use contracts; Cache uses none |
| Container wiring | PASS | 10 contracts resolve; `themes=default` discovered |
| App still serves HTTP | PASS | `GET /api/v1/health` → `{"data":{"status":"ok"},"meta":{}}` |

## Defects found during the wave

| # | Defect | Severity | Fix |
|---|---|---|---|
| 1 | `CacheDependency` archive key was `archive:1` at the call site but `archive` in the service, so listing invalidation silently evicted nothing | **high** — the "archive invalidated" acceptance criterion would have failed silently | added `CacheDependency::archive(siteId)` and made the service use the same factory |
| 2 | `ThemeRegistry::activeFor()` cast the context to the concrete `Site` entity to read `activeTheme()` | medium — worked, but defeated the frozen interface it depends on | read through `SiteRepositoryInterface::findById()` instead |

Defect 1 is the one worth noting: both sides compiled, both sides "looked right", and
the behaviour was a silent no-op. Only a test that asserted the *eviction count*
caught it.

## Judgement calls worth recording

1. **`SiteContextInterface` was not widened to expose `active_theme`.** It is a
   display field; adding it to the tenant contract for one consumer would be the
   wrong trade. Theme reads it through the Site module's repository contract.

2. **Agent 01's `CacheInterface` was implemented, not replaced.** `FileCache`
   satisfies it exactly; a contract test asserts arity per method.

3. **A real `themes/default` was created.** Theme discovery tested against a mock
   directory proves less than discovery of a real theme with real templates, so the
   default theme is functional: head/single/archive/404, a partial pair, CSS, and no
   JavaScript at all — matching the "server-rendered + minimal JS" target.

## Reconciliation with the task packs — items NOT fully delivered

Stated here rather than buried in the reports:

| Module | Task-pack item | Status |
|---|---|---|
| 09 Cache | "Filesystem locks to reduce cache stampede" | **scaffolded only** — `PageCache::locksDirectory()` exists, acquire-around-render is not wired because rendering lives in the public controller layer |
| 10 SEO | "basic SEO scoring" | not implemented (analysis, not core SEO) |
| 10 SEO | sitemap invalidated on content events | `invalidate()` implemented and tested; the cron job that calls it is not registered yet |
| 10 SEO | "Yoast mapping compatibility contract" | contract placeholder only; no importer (Agent 15) |
| 12 Theme | `functions.php` loading | not implemented — needs a sandboxing/capability decision |
| 12 Theme | responsive image helpers | not implemented — needs media variants in the ViewModel |
| 12 Theme | frontend controller that builds ViewModels | out of scope; belongs to the public rendering layer |

None of these block the wave's acceptance criteria as written for the three agents,
but the wave is honestly **not** a complete public frontend yet: nothing serves a
themed page over HTTP. That requires the rendering controller, which is the natural
first task of the next wave.

## Addendum — the public rendering layer was built immediately after this wave

The "Notes for the next wave" below described a public rendering controller as the
missing piece. It was built right away rather than deferred to Wave 7, because
building the REST API on a platform that cannot serve its own public pages would have
been the wrong order.

Result: `http://line.id/blog/...` now renders a themed page over real Apache, with
`X-Cache: MISS` then `HIT`, byte-identical bodies, SEO tags, JSON-LD, breadcrumbs,
attached terms, working 301 redirects and a themed 404 — while `/api/` still returns
the frozen JSON envelope.

That work also uncovered a **Core routing bug** (see
`Public-Rendering-Layer-Report.md`): `Route::compilePattern()` split the path on `/`
with a plain `explode()`, shredding any `{name:<regex>}` constraint that contained a
slash. The catch-all route silently never matched. Fixed in Core with six regression
tests.

Listing pages were then closed too (see `Listing-Pages-Report.md`): `/`, `/blog`,
`/{type}s`, `/term/{slug}`, `/author/{id}` and `/search` all resolve now. That work
found a second real bug — paginated listing pages were served page 1 from the
path-keyed page cache. Fixed by bypassing the cache when a query changes the
representation.

Test count after both addenda: `839 tests, 2726 assertions`.

## Notes for the next wave


- A **public rendering controller** is the missing piece: resolve site → check page
  cache → resolve URL → build ViewModel (content, SEO, breadcrumbs, terms, media) →
  render template → store in page cache with dependencies. It should be a Core-level
  controller wired in `CoreServiceProvider` or a new thin module.
- The render controller must declare `theme:{name}`, `content:{id}`, `archive:{siteId}`
  and `settings:{siteId}` dependencies when it stores a page, otherwise the
  invalidation work in this wave has nothing to act on.
- Register a sitemap-rebuild job in `bin/cron.php` alongside the publishing job.
- The cache stampede lock should be added when that controller exists, since that is
  where the acquire/release boundary actually is.

## Outcome

**PASSED with stated gaps.** Wave 6 accepted for the three assigned modules.

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (804 tests, 2636 assertions)
```

Baseline 686 → 804: **+118 tests**, none of the previous 686 broken.

| Suite | Tests | Assertions |
|---|---|---|
| Unit | 322 | 785 |
| Integration | 314 | 667 |
| Contract | 58 | 780 |
| Security | 103 | 389 |
| Performance | 7 | 15 |
| **Total** | **804** | **2636** |
