# Wave 5 Tracking — Agent 08 Editorial Workflow & Scheduler

Controller log. Records dispatch, decisions, defects and validation gates.

## Pre-flight

- Baseline before wave: `622 tests, 2082 assertions` — green.
- Spec read: task pack lines 1049–1173 (Agent 08); schema §32.
- Agent 08 is a **single-agent wave** (rule G2 is moot): the parallelization
  strategy puts Workflow alone in Wave 5. That also meant the sub-agent failure
  mode from Waves 2–4 could not simply be worked around by running two in
  parallel — there was only one task.

## Dispatch decision — controller-executed

Same reasoning as Waves 3 and 4: the sub-agent mechanism failed twice with
tool-call truncation. Given a single-agent wave, delegating would have added
coordination cost without parallelism benefit.

## Gate results

| Gate | Result | Evidence |
|---|---|---|
| G4 full suite green | PASS | `OK (686 tests, 2259 assertions)` |
| No pre-existing test red | PASS | all 622 Wave 1–4 tests still pass |
| Migration forward | PASS | `20261025000800` applied on `line_cms_test` and `line_cms` |
| Migration rollback | PASS | dropped cleanly, re-applied, suite green |
| Frozen contracts untouched | PASS | no contract signature changed |
| No `site_id = 1` | PASS | grep clean |
| No cross-module table access | PASS | Editorial uses `ScheduledContentFinderInterface`, not `contents` |
| Container wiring | PASS | Editorial contracts resolve; scheduler reachable from `bin/cron.php` |
| Cron entry point | PASS | `php bin/cron.php` reports "Scheduled publishing: 0 item(s) published." |
| App still serves HTTP | PASS | `GET /api/v1/health` → `{"data":{"status":"ok"},"meta":{}}` |

## Design decisions worth recording

1. **The state machine is a data map, not a chain of `if`s.** One reviewable table
   of `from → to => capability`. A unit test enumerates every legal and every
   illegal edge, so adding an edge without a test is hard and removing one breaks
   a test.

2. **Permission is structural, not conventional.** A contract test asserts the
   workflow's constructor takes no `RoleRepository`/`RoleService` and that
   `transition()` takes an `ActorInterface`. "Never inspect role name" is therefore
   verified, not merely intended.

3. **`transitionAsSystem()` is separated from `transition()`.** The scheduler
   genuinely has no actor, so it gets an explicit method that records `user_id = 0`
   and bypasses the capability check. The alternative — minting a synthetic
   all-powerful Actor — would have left a capability-bypass object in the system.

4. **The finder returns pairs, not ids.** The first draft had the scheduler resolve
   a site from a parameter and throw when none was given, which made cross-tenant
   cron impossible. Returning `contentId => siteId` removed the guess entirely.

## Defects found during the wave

| # | Defect | Severity | Fix |
|---|---|---|---|
| 1 | `SchedulerService::siteIdFor()` threw a `RuntimeException` whenever no site was passed, so `publishDue(null)` could never work | **high** — the documented cross-site mode was dead code | finder contract changed to return `contentId => siteId`; scheduler uses the row's own tenant |
| 2 | `ContentService::update()` silently ignored `scheduled_at`, so scheduling had no write path | high — the feature could not work | added `scheduled_at` handling to the accepted attributes |
| 3 | `ModuleDatabaseTestCase` did not know about `workflow_logs`, so 14 integration tests failed with "table not found" | medium — test harness gap | migration and table added to the helper |
| 4 | Test typo `SchedulerServiceInterface.LOCK_NAME` | low | corrected to `::` |

Defect 1 was caught while re-reading my own service before tests existed; defects
2 and 3 were caught by tests. Defect 2 is the notable one: a feature can be
"implemented" and still be unreachable because the write path rejects the field.

## Reconciliation with the task pack

- **"Publish transaction must coordinate content status, revision, URL registry,
  workflow log, timestamps, then commit."** Implemented as a single transaction.
  The URL registry is updated inside `ContentService` during the status change, so
  it participates in the transaction rather than being a separate step. A
  rejected transition leaves zero log rows and leaves content at its original
  status — asserted.
- **"After commit emit event."** Satisfied for all seven workflow events.
  `content.updated` from Content still fires inside the transaction; that is
  Content's own signal from Wave 3 and is documented as a known nuance.

## Notes for Wave 6

- Agent 09 (Cache) should listen for the seven `content.*` workflow events *and*
  Content's `content.updated`/`content.deleted`. The workflow events carry
  `from_status`/`to_status`, which is enough to add on publish and evict on archive
  without re-reading the row.
- Agent 10 (SEO) needs `url_registry` — already populated by Content, and now also
  on publish transitions.
- Agent 12 (Theme) needs `BlockRendererInterface::render($blocks, $resolved)` for
  `related_posts`/`ad_slot`; the resolver belongs there, not in the compiler.
- The `ScheduledContentFinderInterface` pattern is now used three times
  (`MediaUsageChecker`, `ScheduledContentFinder`, and the earlier
  `ContentService` consumption). Any future "module A needs a fact about B's data"
  should follow it rather than querying across boundaries.

## Outcome

**PASSED.** Wave 5 accepted.

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (686 tests, 2259 assertions)
```

Baseline 622 → 686: **+64 tests**, none of the previous 622 broken.

| Suite | Tests | Assertions |
|---|---|---|
| Unit | 269 | 695 |
| Integration | 264 | 558 |
| Contract | 43 | 602 |
| Security | 103 | 389 |
| Performance | 7 | 15 |
| **Total** | **686** | **2259** |
