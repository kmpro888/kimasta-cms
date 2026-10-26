# Revision Module — History & Autosave

Owns lightweight editorial history without unbounded database growth.

## Ownership

Tables: `content_revisions`, `content_autosaves`.

## Contracts provided

| Contract | Implementation |
|---|---|
| `Contracts\RevisionServiceInterface` | `Service\RevisionService` |
| `Contracts\AutosaveServiceInterface` | `Service\AutosaveService` |

## The cap

```text
MAX_PER_CONTENT = 3
```

Enforced immediately after every snapshot, not eventually. Ten updates leave
exactly three revisions. `pruneOldest()` runs a single `DELETE … WHERE id NOT IN
(newest N)`, so the invariant holds even if the table already contained more than
the limit.

## Revision vs autosave

These are deliberately different tables:

| | `content_revisions` | `content_autosaves` |
|---|---|---|
| Trigger | before a destructive content change | every few seconds while typing |
| Key | `(id)` | `(content_id, user_id)` |
| Growth | capped at 3 per content | one row per user per content |
| Effect on content | none (it is a copy) | **none, ever** |

An autosave must never consume the revision budget, and must never alter published
content. Both invariants have tests.

## Dependency direction

This module consumes `ContentServiceInterface`, never the `contents` table:

```text
snapshot() → ContentServiceInterface::get()   (read the current state)
restore()  → ContentServiceInterface::update() (write the old state back)
```

Restore snapshots the state it is about to replace, so a restore is itself
undoable.
