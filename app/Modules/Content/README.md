# Content Module — Content Engine

Owns the canonical publishing entity: content rows, slugs, permalinks and the
derived render/search projections.

## Ownership

Tables: `contents`, `url_registry`.

## Contracts provided

| Contract | Implementation |
|---|---|
| `Contracts\ContentServiceInterface` | `Service\ContentService` |
| `Contracts\ContentRepositoryInterface` | `Repository\PdoContentRepository` |
| `Contracts\ContentQueryServiceInterface` | `Service\ContentQueryService` |
| `Contracts\ContentTypeRegistryInterface` | `Service\ContentTypeRegistry` |
| `Contracts\SlugServiceInterface` | `Service\SlugService` |
| `Contracts\UrlGeneratorInterface` | `Service\UrlGenerator` |
| `Contracts\BlockCompilerInterface` | `Service\PlainBlockCompiler` (fallback) |

## Three representations, one write path

```text
content_json   canonical block JSON          ← authored
content_html   compiled at save time          ← never compiled on a page view
content_plain  plain projection               ← search, excerpt, SEO
```

All three are written together in `ContentService`, so they cannot drift.

## Read paths

| Call | Queries | Why |
|---|---|---|
| `ContentQueryService::findByUrl()` | 2 | one indexed `url_registry` lookup + one primary-key fetch |
| `ContentQueryService::paginate()` | 2 | items + count, no per-row query |

`paginate()` returns `ContentSummary`, a type that simply has no block fields —
so a heavy column cannot leak into a listing by accident. A performance test
asserts the listing SQL never mentions `content_json` / `content_html`.

## URL registry

`url_registry` maps `(site_id, path)` → one entity. That is what makes permalink
resolution a single indexed lookup instead of guessing across tables. A slug
change replaces the mapping (old path stops resolving), and trashing removes it.

## Block compilation seam

`BlockCompilerInterface` is the seam for Agent 11. The default
`PlainBlockCompiler` escapes everything it emits, because a fallback that passed
HTML through would be a stored-XSS hole. Agent 11 rebinds the interface; nothing
in Content changes.

## Multisite

Every repository statement carries `site_id`, including UPDATE and DELETE. The
same slug may exist on two sites. A content id from another site resolves to
`CONTENT_NOT_FOUND`, which is indistinguishable from a genuinely missing row.
