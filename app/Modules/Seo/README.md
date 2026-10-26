# Seo Module — SEO, Sitemap & Redirects

Provides central SEO generation so a theme cannot produce conflicting canonical
logic.

## Ownership

Tables: `content_seo`, `redirects`.

## Contracts provided

| Contract | Implementation |
|---|---|
| `Contracts\SeoServiceInterface` | `Service\SeoService` |
| `Contracts\SitemapServiceInterface` | `Service\SitemapService` |
| `Contracts\RedirectServiceInterface` | `Service\RedirectService` |
| `Contracts\BreadcrumbServiceInterface` | `Service\BreadcrumbService` |

## Single source of truth

`SeoService::metaFor()` returns a **finished** `SeoMeta`. A template calls
`render($meta)` and prints it; it never assembles a canonical or robots value from
parts. That is what satisfies "theme cannot produce conflicting canonical logic".

Defaults are derived (title from content, description from excerpt then plain text,
canonical from the content URL); stored overrides win. Fields are explicit columns in
`content_seo`, never `meta_key`/`meta_value` — so reading SEO is one indexed lookup,
not a join.

## What is rendered

`<title>`, description, canonical, robots, Open Graph (`og:*`), Twitter/X card
metadata, and a Schema.org JSON-LD block. Every interpolated value is escaped, and
the JSON-LD payload escapes `</` so user content cannot break out of the script tag.

## Sitemap is a generated artifact

`generate()` scans published, indexable content and writes
`storage/cache/system/sitemaps/sitemap-{siteId}.xml`. A crawler request reads the
file; it never triggers a content scan. Generation pages through content in batches
of 200 so a large site does not load everything at once.

Noindexed content is excluded — a noindex URL in a sitemap is a contradiction
crawlers report as an error.

## Redirects

Exact lookup on the unique `(site_id, source_path)` key: one indexed hit, no regex,
no scan. Loop detection walks the existing chain and rejects a redirect that would
close it, including a longer cycle that does not include the source path.

`hit_count` exists in the schema but is never incremented synchronously — the
architecture note warns about write amplification on high-traffic redirects.

## Not implemented here

`/sitemap.xml` routing and the redirect middleware belong to the public rendering
layer; this module supplies the services.
