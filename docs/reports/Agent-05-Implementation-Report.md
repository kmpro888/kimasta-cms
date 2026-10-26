# Agent 05 — Taxonomy — Implementation Report

Agent: Agent 05 — Taxonomy
Module: `CMS\Modules\Taxonomy`
Status: DONE

---

## Summary

Implemented taxonomies and terms with `category` (hierarchical) and `tag` (flat)
as defaults, a registry so a plugin can add a custom taxonomy, exact
`content_count` maintenance across attach/detach/sync, and a two-query batch
loader so an archive never issues a term query per post.

Executed by the controller directly; see `Wave-4-Tracking.md` for why the wave
was not delegated.

---

## Files added

```text
app/Modules/Taxonomy/TaxonomyServiceProvider.php
app/Modules/Taxonomy/README.md

app/Modules/Taxonomy/Contracts/TaxonomyRegistryInterface.php
app/Modules/Taxonomy/Contracts/TaxonomyServiceInterface.php
app/Modules/Taxonomy/Contracts/TermRepositoryInterface.php

app/Modules/Taxonomy/Entity/Term.php

app/Modules/Taxonomy/Exceptions/TermNotFoundException.php
app/Modules/Taxonomy/Exceptions/DuplicateTermException.php
app/Modules/Taxonomy/Exceptions/UnknownTaxonomyException.php

app/Modules/Taxonomy/Repository/TaxonomyRepositoryInterface.php
app/Modules/Taxonomy/Repository/PdoTaxonomyRepository.php
app/Modules/Taxonomy/Repository/PdoTermRepository.php

app/Modules/Taxonomy/Service/TaxonomyRegistry.php
app/Modules/Taxonomy/Service/TaxonomyService.php

app/Modules/Taxonomy/Support/TermSlug.php

database/migrations/20261025000600_create_taxonomy_tables.php
```

## Files modified

```text
app/bootstrap.php          provider registered
docs/reports/Wave-4-Tracking.md
```

---

## Contracts consumed

`ClockInterface`, `EventDispatcherInterface`, `DomainEvent`.

## Contracts provided

`TaxonomyRegistryInterface`, `TaxonomyServiceInterface`,
`TermRepositoryInterface` — new frozen contracts in this wave.

---

## Database migrations

| Migration | Tables |
|---|---|
| `20261025000600_create_taxonomy_tables.php` | `taxonomies`, `terms`, `content_terms` |

Follows `docs/02-Database-Schema.md` §24–26. `content_terms.site_id` is
deliberately denormalised so a tenant-scoped term lookup is index-only without a
join. Verified forward and rollback.

---

## Events emitted

| Event | When |
|---|---|
| `term.created` | after a term is inserted |
| `term.updated` | after name/description/slug/parent changes |
| `term.deleted` | after a term is removed |

Note: the task pack also lists `taxonomy.created/updated/deleted`. Those were
**not** emitted, because a taxonomy here is created by `ensureTaxonomy()` as an
idempotent side effect of using it, not as an authored action. Emitting a
"created" event on an implicit ensure would fire spuriously and there is no
delete path for a taxonomy in this module's scope. If a later agent adds explicit
taxonomy administration, those three events belong there.

## Events consumed

None.

---

## API changes

None. Admin term management routes belong to a later agent.

---

## Performance

| Operation | Queries |
|---|---|
| `termsForContents()` for a page of 10 posts | **2** (associations + terms) |
| `termsForContents()` with an empty id list | **0** |
| archive page term lookup | never per-post |

The batch loader was initially written with a per-term `findById()` loop; a
performance test exposed the N+1 and `findByIds()` was added to the contract. The
test now pins the count at 2.

---

## Multisite impact

- Every repository statement carries `site_id`.
- A foreign term id resolves to `TERM_NOT_FOUND`.
- A parent term from another site is rejected.
- Content on site B cannot be tagged with site A's term.
- The same slug may exist on two sites, and in two taxonomies on the same site.
- `content_count` is never perturbed by another tenant.

---

## Tests added

- unit: `TaxonomyRegistryTest` (9), `TermSlugTest` (11)
- integration: `TaxonomyServiceTest` (25)
- contract: `TaxonomyContractTest` (4)
- security: `TaxonomyIsolationTest` (9)

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (622 tests, 2082 assertions)
```

Taxonomy contributes 58 of those.

---

## Known limitations

1. No term reparenting cycle detection beyond "a term cannot be its own parent".
   A → B → A cycle is not prevented; the schema permits it and no UI in this scope
   creates it. A tree-walk guard belongs with the admin UI.
2. `taxonomy.*` events are not emitted (see the Events note above).
3. Term deletion does not compact `content_count` on ancestors — each term counts
   only its own direct associations, which matches the schema.
4. A taxonomy row is never deleted by this module; `createTaxonomy`-style removal
   is out of scope.

---

## CONTRACT_CHANGE_REQUEST

none

---

## Ready to merge

yes
