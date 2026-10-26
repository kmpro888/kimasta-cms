# Editorial Module — Workflow & Scheduler

Owns the editorial state machine and scheduled publishing.

## Ownership

Table: `workflow_logs`.

## Contracts provided

| Contract | Implementation |
|---|---|
| `Contracts\EditorialWorkflowInterface` | `Service\EditorialWorkflow` |
| `Contracts\SchedulerServiceInterface` | `Service\SchedulerService` |
| `Contracts\WorkflowLogRepositoryInterface` | `Repository\PdoWorkflowLogRepository` |

## The state machine is data

`Workflow\WorkflowStateMachine` holds the graph and the capability each edge
requires, in one map — so "who may do what" is reviewable in a single place.

```text
draft ──submit──▶ submitted ──start review──▶ review
review ──request revision──▶ revision_requested ──resubmit──▶ submitted
review ──approve──▶ approved
approved ──schedule──▶ scheduled ──(cron)──▶ published
approved ──publish──▶ published
published ──archive──▶ archived
```

| Edge | Capability |
|---|---|
| submit / resubmit | `workflow.submit` |
| review, request revision, approve | `workflow.review` |
| schedule, publish, archive | `content.publish` |

## Permission is never a role check

Every decision goes through `ActorInterface::can()`. Nothing in this module
mentions a role name, so a site can re-point a capability without a code change.
"Contributor cannot publish" is therefore a data fact, asserted by a test that uses
a real Actor with a real capability set.

## Absence of `setStatus` duplication

`ContentServiceInterface::setStatus()` exists as a low-level setter, but this
module deliberately uses `update()` inside its own transaction instead, so the
audit row and the revision snapshot commit or roll back together with the status
change.

## Publish transaction

```text
BEGIN
  revision snapshot        (captures what is being replaced)
  content status update    (+ scheduled_at when scheduling)
  workflow_logs insert     (from_status → to_status, actor, note)
COMMIT
  → workflow event dispatched (only after commit)
```

If a caller already opened a transaction, this module joins it rather than nesting.
A listener therefore never observes a publish that was rolled back.

Note: `ContentService` emits its own `content.updated` during the status change,
which is inside this transaction. The *workflow* events are strictly post-commit.

## Administrative override

`transition(..., administrative: true)` skips the graph but still requires
`content.publish`, and the note is suffixed `[administrative override]` so the log
explains itself. It is not a silent superpower.

## Scheduler

```text
php bin/cron.php
```

- Takes the `editorial-scheduler` advisory lock; `flock` releases it automatically
  if the process dies, so a crash cannot wedge the queue.
- Publishes at most `DEFAULT_BATCH` (50) per run — a backlog drains across runs
  instead of holding a shared-host request open.
- Records publishing with `user_id = 0` so the audit trail shows it was automated.
- One failing row does not abort the batch; the next run retries it.

## Tenant safety

The scheduler asks `ScheduledContentFinderInterface` for due content and receives
**content id ⇒ site id** pairs. It never guesses a tenant. Content implements the
finder because Content owns `scheduled_at`; Editorial never queries `contents`.
