# Taxonomy Module — Categories, Tags & Custom Taxonomies

Owns classification: taxonomies, terms and the content↔term association.

## Ownership

Tables: `taxonomies`, `terms`, `content_terms`.

## Contracts provided

| Contract | Implementation |
|---|---|
| `Contracts\TaxonomyRegistryInterface` | `Service\TaxonomyRegistry` |
| `Contracts\TaxonomyServiceInterface` | `Service\TaxonomyService` |
| `Contracts\TermRepositoryInterface` | `Repository\PdoTermRepository` |

## Default taxonomies

| Name | Hierarchical |
|---|---|
| `category` | yes — supports parent/child |
| `tag` | no — flat |

A plugin registers a custom taxonomy with `TaxonomyRegistryInterface::register()`
and is treated identically to a built-in one. No code path switches on a taxonomy
name.

## Registry vs rows

The registry says what a taxonomy *is*; the `taxonomies` table says which ones a
tenant *has*. `ensureTaxonomy()` bridges them, so registering a taxonomy in code
does not require a migration, and `seedDefaults()` materialises both defaults for
a new site.

## content_count discipline

`terms.content_count` is a precomputed counter, because an archive page must never
run `COUNT(*)`. It is adjusted in the same operation that changes an association,
and only when the association row actually changed:

- `attach()` — `INSERT IGNORE`; the counter moves only if a row was inserted, so a
  repeated attach cannot double count.
- `detach()` — `DELETE`; the counter moves only if a row was deleted.
- `sync()` — diffs the current and desired sets, then attaches/detaches the delta.
- The update uses `GREATEST(0, …)` so a delete racing an attach cannot wrap an
  UNSIGNED column.

## Batch loading

`termsForContents()` costs **two queries for the whole page** (associations + term
rows), regardless of post or term count. A performance test asserts exactly that
against the query profiler. An archive with 20 posts does not issue 20 term
queries.

## Slug semantics

- Derived from the name → collision is auto-suffixed (`news`, `news-2`).
- Supplied explicitly → collision raises `SLUG_ALREADY_EXISTS`, because silently
  changing a URL the caller asked for is surprising.

## Deleting a term

Detaches it from content and never deletes content. The association rows cascade;
`contents` is not touched. A test asserts the post survives with its title intact.
