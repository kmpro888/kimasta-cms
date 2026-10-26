# Agent 07 — Revision & Autosave — Implementation Report

Agent: Agent 07 — Revision & Autosave
Module: `CMS\Modules\Revision`
Status: DONE

---

## Summary

Implemented capped revision history (3 per content row) and per-user draft
autosave in a separate table. Autosave never consumes the revision budget and
never alters published content.

Executed by the controller directly; see `Wave-4-Tracking.md`.

---

## Files added

```text
app/Modules/Revision/RevisionServiceProvider.php
app/Modules/Revision/README.md

app/Modules/Revision/Contracts/RevisionServiceInterface.php
app/Modules/Revision/Contracts/AutosaveServiceInterface.php

app/Modules/Revision/Entity/Revision.php

app/Modules/Revision/Exceptions/ContentMissingException.php
app/Modules/Revision/Exceptions/RevisionNotFoundException.php

app/Modules/Revision/Repository/RevisionRepositoryInterface.php
app/Modules/Revision/Repository/PdoRevisionRepository.php

app/Modules/Revision/Service/RevisionService.php
app/Modules/Revision/Service/AutosaveService.php

database/migrations/20261025000700_create_revision_tables.php
```

## Files modified

```text
app/bootstrap.php            provider registered
docs/reports/Wave-4-Tracking.md
```

---

## Contracts consumed

```text
CMS\Modules\Content\Contracts\ContentServiceInterface   ← the important one
CMS\Shared\Contracts\ClockInterface
```

The dependency direction is deliberate: revisions read and restore content
through the Content **service contract**, never through the `contents` table. A
contract test asserts the constructor has no `PdoContentRepository` dependency.

## Contracts provided

`RevisionServiceInterface`, `AutosaveServiceInterface`.

---

## Database migrations

| Migration | Tables |
|---|---|
| `20261025000700_create_revision_tables.php` | `content_revisions`, `content_autosaves` |

Follows `docs/02-Database-Schema.md` §22–23. `content_autosaves` is keyed
`(content_id, user_id)` so one user has exactly one live draft per post. Both
tables cascade on content delete. Verified forward and rollback.

---

## Events emitted

None. A revision is history, not a domain action another module reacts to; the
`content.updated` event that follows a restore is already emitted by Content.

## Events consumed

None.

---

## API changes

None.

---

## Cache impact

`restore()` goes through `ContentServiceInterface::update()`, which emits
`content.updated` with the path — so a restore invalidates the page cache exactly
like any other content change. No separate cache path is needed.

---

## Multisite impact

- Every repository statement carries `site_id`.
- A revision id from another site resolves to `REVISION_NOT_FOUND`.
- A snapshot of foreign content raises `CONTENT_NOT_FOUND` (Content's own scoping
  does the work).
- Autosaves are partitioned by `(content_id, user_id, site_id)`.

---

## The cap, verified

| Scenario | Result |
|---|---|
| 10 snapshot calls | 3 revisions remain |
| 4 snapshots | oldest removed |
| restore | snapshots the replaced state first, so it is undoable |
| 5 autosaves | 0 revisions created |
| autosave after publish | published HTML unchanged |

---

## Tests added

- integration: `RevisionServiceTest` (14), `AutosaveServiceTest` (13)
- contract: `RevisionContractTest` (5)

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (622 tests, 2082 assertions)
```

Revision contributes 32 of those.

---

## Known limitations

1. Restore replaces title/excerpt/blocks. Status and taxonomy associations are
   **not** versioned, so restoring an old revision does not revert a publish or a
   term change. That matches "snapshot previous relevant state" — status history
   is the workflow module's business.
2. Revisions store `content_html` as well as `content_json`, which duplicates
   data. It is kept so a restored page renders without recompiling, at the cost of
   at most 3 copies per post.
3. No diff view; revisions are whole snapshots, not patches.
4. Autosave has no automatic expiry. A stale draft persists until the user saves
   or `clear()` is called.

---

## CONTRACT_CHANGE_REQUEST

none

---

## Ready to merge

yes
