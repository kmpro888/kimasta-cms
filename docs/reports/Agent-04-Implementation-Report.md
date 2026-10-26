# Agent 04 — Content Engine — Implementation Report

Agent: Agent 04 — Content Engine
Module: `CMS\Modules\Content`
Status: DONE

---

## Summary

Implemented the canonical publishing domain: content rows with three
synchronised representations (`content_json`, `content_html`, `content_plain`),
slug normalization with collision resolution, a URL registry for one-lookup
permalink resolution, a content type registry with `post`/`page` plus custom
types, and a read/query service whose listing projection cannot carry the block
payload.

Executed by the controller directly. The Wave 2 sub-agent failure pattern (tool
call truncation) recurred, so this wave was not delegated; see
`Wave-3-Tracking.md`.

---

## Files added

```text
app/Modules/Content/ContentServiceProvider.php
app/Modules/Content/README.md

app/Modules/Content/Contracts/ContentServiceInterface.php
app/Modules/Content/Contracts/ContentRepositoryInterface.php
app/Modules/Content/Contracts/ContentQueryServiceInterface.php
app/Modules/Content/Contracts/ContentTypeRegistryInterface.php
app/Modules/Content/Contracts/SlugServiceInterface.php
app/Modules/Content/Contracts/UrlGeneratorInterface.php
app/Modules/Content/Contracts/BlockCompilerInterface.php

app/Modules/Content/Entity/Content.php
app/Modules/Content/Entity/ContentSummary.php

app/Modules/Content/Exceptions/ContentNotFoundException.php
app/Modules/Content/Exceptions/ContentNotPurgeableException.php
app/Modules/Content/Exceptions/InvalidContentDataException.php
app/Modules/Content/Exceptions/InvalidStatusException.php
app/Modules/Content/Exceptions/UnknownContentTypeException.php
app/Modules/Content/Exceptions/UrlCollisionException.php

app/Modules/Content/Repository/PdoContentRepository.php
app/Modules/Content/Repository/PdoUrlRegistryRepository.php
app/Modules/Content/Repository/UrlRegistryRepositoryInterface.php

app/Modules/Content/Service/ContentService.php
app/Modules/Content/Service/ContentQueryService.php
app/Modules/Content/Service/ContentTypeRegistry.php
app/Modules/Content/Service/SlugService.php
app/Modules/Content/Service/UrlGenerator.php
app/Modules/Content/Service/PlainBlockCompiler.php

app/Modules/Content/Support/ContentMediaUsageChecker.php

database/migrations/20261025000400_create_content_tables.php
```

## Files modified

```text
app/bootstrap.php                      provider registered
app/Modules/Content/ContentServiceProvider.php   binds the Media usage checker (own table)
docs/reports/Wave-3-Tracking.md        controller log
```

---

## Contracts consumed

```text
CMS\Shared\Contracts\ClockInterface
CMS\Shared\Contracts\EventDispatcherInterface
CMS\Shared\Contracts\ConfigInterface            via the provider
CMS\Core\Events\DomainEvent
CMS\Modules\Media\Contracts\MediaUsageCheckerInterface   implemented, not consumed
```

## Contracts provided

All seven in `app/Modules/Content/Contracts/` (see the table above). These are
new frozen contracts: no later module may widen or narrow them without a
`CONTRACT_CHANGE_REQUEST`.

Note on one design correction made during validation: the repository's
`create()` initially took `site_id` inside a free-form `$attributes` array. A
contract test caught it and it was changed to `create(int $siteId, array
$attributes)` — a tenant boundary that lives inside an array is a boundary a
caller can forget.

---

## Database migrations

| Migration | Tables |
|---|---|
| `20261025000400_create_content_tables.php` | `contents`, `url_registry` |

Follows `docs/02-Database-Schema.md` §17 and §68. `status` is VARCHAR rather than
ENUM so a workflow migration can extend the vocabulary without an ALTER on a
large table. Verified forward and rollback on `line_cms_test`.

---

## Events emitted

| Event | When | Payload |
|---|---|---|
| `content.created` | after insert + URL registration | `id`, `site_id`, `type`, `slug`, `status`, `author_id`, `path` |
| `content.updated` | after any change, including a status change | same |
| `content.deleted` | on trash **and** on purge | same |

`content.deleted` fires on trash as well as purge because a trashed post must
disappear from the public site immediately — that is exactly the signal cache
invalidation needs. The `path` field is included so a listener can purge one
specific page instead of clearing a whole site cache.

Workflow-specific events (`content.published`, `content.submitted`) are Agent 08's
to emit; this module does not pre-empt them.

## Events consumed

None.

---

## API changes

None. No routes registered; the public renderer and admin API belong to later
agents.

---

## Cache impact

- `ContentService` emits a precise `path` with every create/update/delete, which
  is what allows targeted invalidation instead of a site-wide flush.
- `findByUrl()` costs 2 queries, so a cache miss is cheap and a cache hit needs
  zero content queries.
- `paginate()` returns `ContentSummary`, which physically lacks the block fields,
  so a listing cannot accidentally become an expensive query.

## Multisite impact

- Every repository statement carries `site_id`, including UPDATE and DELETE.
- `findByUrl` resolves within the site partition of `url_registry`.
- The same slug and the same path may exist on two sites independently.
- Cross-site read, update, trash and purge all behave as `CONTENT_NOT_FOUND`
  (or a false return), never as a leak.

---

## Security impact

- The default block compiler escapes every emitted value. A stored-XSS test
  asserts `<script>` never survives into `content_html`.
- Slugs are transliterated with an explicit Latin table rather than
  `iconv//TRANSLIT`, because iconv is not portable: on Windows it turns `Café`
  into `caf-e`, which would make a public URL depend on the host OS.
- `purge()` refuses content that is not already trashed, so a single careless
  call cannot destroy published rows.
- Paths are normalized (query string and fragment stripped, duplicate slashes
  collapsed) before registry lookup, so `/blog/x/?utm=1` cannot miss the cache
  key or panic the resolver.

---

## Tests added

- unit: `SlugServiceTest` (23), `ContentTypeRegistryTest` (8),
  `PlainBlockCompilerTest` (11)
- integration: `ContentServiceTest` (31), `ContentQueryTest` (15)
- contract: `ContentContractTest` (5)
- security: `ContentIsolationTest` (11)
- performance: `ContentQueryCountTest` (7)

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (468 tests, 1314 assertions)
```

Full suite, all testsuites. Content contributes 111 of those.

---

## Query/performance notes

Measured with the Core `QueryProfiler` in the performance suite:

| Operation | Queries |
|---|---|
| `findByUrl()` | **2** (registry lookup + row fetch) |
| `paginate()` | **2** (items + count) |
| listing with 1 row vs 12 rows | **identical** — no N+1 |
| `create()` | ≤ 6 (slug probe, insert, read-back, URL upsert) |

The task pack target was "≤ 5 logical queries" for an uncached single retrieval;
the implementation is at 2.

No `SELECT *` anywhere: a test asserts the literal pattern never appears in a
profiled hot-path query.

---

## Known limitations

1. **No permalink history.** A slug change removes the old path rather than
   keeping a redirect, because `url_registry` has a single `canonical` flag and
   redirect management is not in this agent's scope. Old URLs therefore 404 until
   a redirect module lands.
2. `setStatus()` validates that a status is *known* but does not enforce
   transition rules. That is deliberate — the workflow module owns the state
   machine, and duplicating it here would create two sources of truth.
3. Page hierarchy (`hierarchical: true`) is recorded in the type registry but not
   used to build nested paths yet; paths are `/{prefix}/{slug}`.
4. The bundled `PlainBlockCompiler` handles paragraph, heading, list, quote, code
   and image blocks only. Agent 11 replaces it through the interface.
5. `UrlGenerator::prefixFor()` maps `post` → `/blog` and other types to
   `/{type}s`; a configurable permalink pattern is a later concern.

---

## CONTRACT_CHANGE_REQUEST

none

Content's seven interfaces are new in this wave and now frozen. `SiteContextInterface`,
`ActorInterface` and the REST envelopes were untouched.

---

## Ready to merge

yes
