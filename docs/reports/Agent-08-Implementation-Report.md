# Agent 08 — Editorial Workflow & Scheduler — Implementation Report

Agent: Agent 08 — Editorial Workflow & Scheduler
Module: `CMS\Modules\Editorial`
Status: DONE

---

## Summary

Implemented the editorial state machine as a single declarative graph, capability
based permission (never role-name inspection), one transaction per transition that
covers status + revision + audit row, post-commit event dispatch, and a cron-driven
scheduler that publishes due content in locked, bounded batches.

Executed by the controller directly; see `Wave-5-Tracking.md`.

---

## Files added

```text
app/Modules/Editorial/EditorialServiceProvider.php
app/Modules/Editorial/README.md

app/Modules/Editorial/Contracts/EditorialWorkflowInterface.php
app/Modules/Editorial/Contracts/SchedulerServiceInterface.php
app/Modules/Editorial/Contracts/WorkflowLogRepositoryInterface.php
app/Modules/Editorial/Contracts/ScheduledContentFinderInterface.php

app/Modules/Editorial/Entity/WorkflowLog.php

app/Modules/Editorial/Exceptions/IllegalTransitionException.php
app/Modules/Editorial/Exceptions/WorkflowPermissionDeniedException.php
app/Modules/Editorial/Exceptions/ContentMissingException.php

app/Modules/Editorial/Repository/PdoWorkflowLogRepository.php

app/Modules/Editorial/Service/EditorialWorkflow.php
app/Modules/Editorial/Service/SchedulerService.php

app/Modules/Editorial/Workflow/WorkflowStateMachine.php
app/Modules/Editorial/Support/NullScheduledContentFinder.php

app/Modules/Content/Support/ContentScheduledFinder.php

database/migrations/20261025000800_create_workflow_logs_table.php
```

## Files modified

```text
app/bootstrap.php                                provider registered (before Block)
bin/cron.php                                     scheduler job wired, provider list aligned
app/Modules/Content/ContentServiceProvider.php    binds the scheduled-content finder
app/Modules/Content/Service/ContentService.php    update() now accepts scheduled_at
docs/reports/Wave-5-Tracking.md
```

`ContentService::update()` did not handle `scheduled_at`, although `create()` did.
Scheduling therefore had no legitimate write path. Adding the key to the accepted
`$attributes` is additive — the method signature is unchanged and the documented
array simply gains a member.

---

## Contracts consumed

```text
CMS\Modules\Content\Contracts\ContentServiceInterface
CMS\Modules\Revision\Contracts\RevisionServiceInterface
CMS\Shared\Contracts\ActorInterface
CMS\Shared\Contracts\ClockInterface
CMS\Shared\Contracts\EventDispatcherInterface
CMS\Core\Filesystem\LockInterface
CMS\Core\Database\Connection
```

## Contracts provided

`EditorialWorkflowInterface`, `SchedulerServiceInterface`,
`WorkflowLogRepositoryInterface`, `ScheduledContentFinderInterface`.

### Cross-module seam (third use of the same pattern)

The scheduler must know which content is due, but `scheduled_at` lives in
`contents`, which Editorial does not own. Resolution:

- Editorial declares `ScheduledContentFinderInterface`, returning **content id ⇒
  site id** pairs.
- Content implements it (`ContentScheduledFinder`) because Content owns the table.
- Container prefers the Content binding; Editorial keeps a `Null` default.

Returning pairs rather than bare ids is deliberate: a cross-tenant cron run must
never have to guess which site a row belongs to.

---

## Database migrations

| Migration | Tables |
|---|---|
| `20261025000800_create_workflow_logs_table.php` | `workflow_logs` |

Follows `docs/02-Database-Schema.md` §32. Verified forward and rollback.

---

## Events emitted

All post-commit, all carrying `id`, `site_id`, `from_status`, `to_status`,
`user_id`, `note`:

| Transition target | Event |
|---|---|
| submitted | `content.submitted` |
| review | `content.review_started` |
| revision_requested | `content.revision_requested` |
| approved | `content.approved` |
| scheduled | `content.scheduled` |
| published | `content.published` |
| archived | `content.archived` |

A failed transition emits nothing — asserted by a test.

**Honest nuance:** `ContentService` emits its own `content.updated` during the
status change, which happens *inside* the transaction. The seven workflow events
above are strictly post-commit; `content.updated` is Content's own signal and was
already shipping before this wave.

## Events consumed

None.

---

## API changes

None. Publishing endpoints belong to Agent 13.

---

## Cache impact

Every transition emits an event carrying `id`, `site_id` and the new status, which
is what the cache layer needs for targeted invalidation:
`content.published` adds a page to cache, `content.archived` removes it.

The scheduler publishes in bounded batches, so a backlog does not produce one huge
cache-invalidation burst.

---

## Multisite impact

- Every log row and query is site-scoped.
- A foreign content id raises `CONTENT_NOT_FOUND`.
- The scheduler derives the tenant per row from the finder's pairs, never from a
  default.
- A site-scoped run ignores other tenants entirely (asserted).

---

## Transaction behaviour

```text
BEGIN
  revision snapshot
  content status update (+ scheduled_at)
  workflow_logs insert
COMMIT
  → workflow event
```

Verified by the illegal-transition test: a rejected transition leaves content at
`draft` **and** writes zero log rows, proving nothing partial is persisted.

---

## Tests added

- unit: `WorkflowStateMachineTest` (21 — every legal edge, every illegal edge, the
  frozen error code, reachability)
- integration: `EditorialWorkflowTest` (25), `SchedulerServiceTest` (13)
- contract: `EditorialContractTest` (5)

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (686 tests, 2259 assertions)
```

Editorial contributes 64 of those.

---

## Known limitations

1. **`content.*` workflow events are emitted post-commit, but Content's own
   `content.updated` fires inside the transaction.** Making that fully
   transactional would require a deferred-dispatch mechanism in Core, which is a
   larger change than this wave warrants. Documented rather than hidden.
2. The scheduler retries a failed row silently on the next run; there is no
   dead-letter or alerting. On shared hosting a log line is the realistic ceiling.
3. `schedule()` cannot schedule from `draft` directly — the path must go through
   review. An administrator can force it with the override, which is audited.
4. Archived content cannot be republished without an administrative override; the
   task pack's graph has `archived` as terminal.
5. `workflow_logs` grows without pruning. At the volume an editorial team produces
   this is negligible, but a retention job is a reasonable later addition.

---

## CONTRACT_CHANGE_REQUEST

none

No frozen contract was modified. Content's `ContentServiceInterface` signature is
unchanged; `update()` gained support for a key it already documented for `create()`.

---

## Ready to merge

yes
