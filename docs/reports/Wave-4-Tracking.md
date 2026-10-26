# Wave 4 Tracking — Agent 05 Taxonomy + Agent 07 Revision + Agent 11 Blocks

Controller log. Records dispatch, order, defects and validation gates.

## Pre-flight

- Baseline before wave: `468 tests, 1314 assertions` — green.
- Contracts available: `SiteContextInterface`, `ActorInterface`, `ClockInterface`,
  `ConfigInterface`, `EventDispatcherInterface`, and Content's seven contracts.
- Spec read: task pack lines 711–823 (05), 963–1048 (07), 1442–1570 (11);
  schema §22–26.
- Note: Agent 11 has no tables, so this wave adds two migrations, not three.

## Dispatch decision — controller-executed, sequential

Same reasoning as Wave 3: the sub-agent mechanism failed twice with tool-call
truncation. Wave 4 was implemented in one context.

Execution order was chosen for dependency safety, not convenience:

```text
1. Agent 05 Taxonomy     (independent; depends on Content contracts only)
2. Agent 07 Revision     (depends on Content service contract)
3. Agent 11 Block        (must run LAST — it rebinds Content's compiler)
```

Agent 11 had to be last for a concrete reason: `BlockServiceProvider` overrides
the `BlockCompilerInterface` binding that Content registers. Registering it
earlier would have been overwritten by Content's fallback, silently reverting the
whole engine. This ordering constraint is now documented in `app/bootstrap.php`.

Rule G2 (parallel within a wave) again could not be honoured without the sub-agent
mechanism; this is stated rather than papered over.

## Gate results

| Gate | Result | Evidence |
|---|---|---|
| G4 full suite green | PASS | `OK (622 tests, 2082 assertions)` |
| No pre-existing test red | PASS | all 468 Wave 1–3 tests still pass |
| Migration forward | PASS | 2 migrations applied on `line_cms_test` |
| Migration rollback | PASS | both dropped cleanly; green after re-apply |
| Dev DB migrated | PASS | `line_cms` has taxonomies, terms, content_terms, content_revisions, content_autosaves |
| Frozen contracts untouched | PASS | no `app/Shared/Contracts/**` change |
| No `site_id = 1` | PASS | grep clean |
| No cross-module table access | PASS | Revision uses `ContentServiceInterface`, not `contents` |
| Container wiring | PASS | 13 contracts resolve; compiler rebind confirmed |
| App still serves HTTP | PASS | `GET /api/v1/health` → `{"data":{"status":"ok"},"meta":{}}` |

## Defects found by tests during the wave

| # | Defect | Severity | Fix |
|---|---|---|---|
| 1 | `termsForContents()` resolved term rows with a per-term `findById()` loop — an N+1 in the archive path | **high** — the batch loader is the point of the requirement | added `findByIds()` to the contract and a single `IN (...)` query; performance test pins it at 2 queries |
| 2 | Explicit term slug was silently auto-suffixed instead of rejected | medium — a caller asking for a specific URL got a different one | derived slugs still suffix; explicit slugs now raise `SLUG_ALREADY_EXISTS` |
| 3 | `section` block collected no plain text from its children | medium — restored/compiled plain projections lost nested content | `renderNested()` returns both projections; `section` aggregates |
| 4 | `columns` block used the old string return of `renderNested()` after the signature changed | high if shipped — would have been a type error | updated call site; caught immediately by the compiler test |
| 5 | Compiler cast to `instanceof BlockRegistry` to reach `schemaFor()` | low — worked but broke the abstraction the interface exists for | `schemaFor()`/`versionFor()`/`allowsRawHtml()` moved onto `BlockRegistryInterface` |

Defect 1 is the one worth highlighting: it is exactly the requirement the task
pack called out ("Content archive must avoid per-post term queries"), and it was
written wrong first, then caught by a query-count test rather than by review.

## Reconciliation with the task pack

Two places where the implementation deliberately diverges, both documented rather
than silently skipped:

1. **`taxonomy.created/updated/deleted` are not emitted.** A taxonomy row here is
   created implicitly by `ensureTaxonomy()`. Emitting "created" on an idempotent
   ensure would fire spuriously. See the Agent 05 report.
2. **`html` block capability gating is not enforced in this layer.** The sanitizer
   is unconditional; the capability check needs an Actor at the request layer.

## Notes for Wave 5

- Agent 08 (Workflow) owns content status transition rules.
  `ContentServiceInterface::setStatus()` is the low-level setter — do not
  duplicate the state machine there.
- `RevisionServiceInterface::snapshot()` should be called before destructive
  updates; workflow status changes should not necessarily snapshot.
- `BlockRendererInterface::dynamicBlockIndices()` + `render($blocks, $resolved)` is
  the seam for preparing `related_posts` and `ad_slot` data. A resolver must not be
  placed inside the compiler.
- `MediaUsageCheckerInterface` pattern is the template for any future
  "module A needs a fact about module B's data" question: declare the interface in
  the asking module, implement it in the owning module.

## Outcome

**PASSED.** Wave 4 accepted.

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (622 tests, 2082 assertions)
```

Baseline 468 → 622: **+154 tests**, none of the previous 468 broken.

| Suite | Tests | Assertions |
|---|---|---|
| Unit | 244 | 642 |
| Integration | 230 | 494 |
| Contract | 38 | 542 |
| Security | 103 | 389 |
| Performance | 7 | 15 |
| **Total** | **622** | **2082** |
