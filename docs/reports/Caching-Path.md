# Is This Full-Page Caching?

The question, with a diagram:

```text
Visitor
   ↓
Apache
   ↓
Full Page Cache
   ↓
HTML
```

Short answer: **no, not in that shape.** What exists is an application-level page cache. The
cache is consulted *inside* PHP, after the framework has booted. Nothing outside PHP caches
anything.

```text
what the diagram suggests            what actually happens
─────────────────────────            ──────────────────────────────
Visitor                              Visitor
  ↓                                    ↓
Apache                               Apache
  ↓                                    ↓
Full Page Cache  ──▶ HTML            PHP starts
                                       ↓
                                     container (21 providers)
                                       ↓
                                     router match
                                       ↓
                                     middleware chain
                                       ↓
                                     PageCache lookup  ──▶ HTML
```

---

## The evidence

**1. The application runs on every hit.** Every cached response carries a **different**
`X-Request-ID` (5 out of 5 requests, all unique). A cache sitting in front of PHP would replay
the stored response, including its id. The middleware demonstrably executed.

**2. Nothing outside PHP caches it.** `public/.htaccess` contains only rewrite rules and a
`nosniff` header — no `mod_cache`, no `mod_expires`. And the HTML is served with:

```http
Cache-Control: public, max-age=0, must-revalidate
```

which **forbids** browsers and any CDN from serving it without asking the origin again. A CDN
placed in front would still reach PHP on every request.

**3. What the cache does save is real.** Measured through the running application, with the
query profiler attached to the container's connection:

```text
article, cold   MISS   18 queries
article, warm   HIT     0 queries
home,    cold   MISS   10 queries
home,    warm   HIT     0 queries
```

So the promise the architecture makes — **zero database queries on a cache hit** — holds. What
is skipped is the database work and the rendering. What is still paid is PHP startup, the
container, routing and the middleware chain.

---

## Why the HTML is not cacheable downstream

`max-age=0, must-revalidate` is a deliberate consequence of the design, not an oversight: the
platform invalidates pages itself, by dependency, on the filesystem. It cannot tell a CDN that a
page changed, so it asks every downstream cache to revalidate. That keeps "an edit is visible
immediately" true at the cost of any edge caching.

Making the diagram real would mean one of:

| Option | Needs | Trade-off |
|---|---|---|
| **Apache `mod_cache` / `mod_expires`** | real TTLs on public HTML, and a purge mechanism (a module that cannot easily be told "this page changed") | an edit could stay invisible for the TTL |
| **A CDN with tag-based purge** | sending `Cache-Tag`/`Surrogate-Key` per page and purging on the content events already emitted | requires a CDN; not available on shared hosting |
| **Serving static HTML from disk with rewrite fallback** | writing the cache file to a public path and a rewrite rule that serves it when present | an edit must delete the file — doable, and the invalidation events already exist |

The third is the only one that fits the "no extra infrastructure" rule this project follows. It
is a real option, not fantasy: the page cache already writes full HTML per site and path, and the
invalidation events already fire. It would mean serving `/blog/x` from `public/cache/x.html` when
that file exists, with PHP only as the fallback.

---

## A defect this question uncovered

Asking "is it really zero queries" made me measure instead of trusting the test, and the first
measurement said **1 query on a hit**, not zero.

Cause: the compiled domain map (`storage/cache/system/domain-map.php`) contained
`example.com => 8501` — a site from the **test database**. The test harness regenerates the map
from whatever database it is pointed at, and the map was a single shared file. So:

- running the suite overwrote the map the development site reads;
- the development site then fell back to a database lookup for its own host on **every** request,
  which is exactly the extra query;
- and the dangerous version: a live domain present in the map pointing at an id that exists only
  in the test database would make the whole site **404** until the map was rebuilt.

Fixed by scoping the artifact to its database:

```text
before   storage/cache/system/domain-map.php            (shared by every database)
after    storage/cache/system/line_cms/domain-map.php
        storage/cache/system/line_cms_test/domain-map.php
```

`tests/Integration/Modules/Site/DomainMapScopeTest.php` pins it with three tests, including that
regenerating writes only the scoped file.

After the fix, measured again: **MISS 18 queries, HIT 0**.

---

## Summary

| Claim | True? |
|---|---|
| A cache hit costs zero database queries | **yes**, when the domain map is warm |
| Rendering is skipped on a hit | **yes** |
| PHP does not run on a hit | **no** |
| Apache serves the page without PHP | **no** |
| A CDN would help | **no** — the HTML forbids downstream caching |
| It is "full page caching" in the sense of the diagram | **no** — it is application-level |

The architecture document describes the target as
`Request → Site/Domain Cache → Full Page Cache → HTML`. What was built matches the *database*
part of that promise exactly, and does not yet match the *serving* part.
