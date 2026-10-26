# Agent 14C — Frontend Submission — Implementation Report

Agent: Agent 14C — Frontend Submission
Module: `CMS\Modules\Contribution`
Status: DONE for the controllers/rules layer. The **HTML dashboard is not built** — see
Known limitations.

---

## What the task pack asked for

```text
Ownership: contributor-facing dashboard/controllers/UI
Must reuse: ContentService, EditorialWorkflow, MediaService
Features: draft, edit own, media, submit, revision request, resubmit, status history,
          published list
"No separate publishing logic."
```

The repository has **no UI layer at all** — no admin shell, no forms, no layout. So the
part that can be built honestly is the contributor **rules and controllers**: the single
place that decides what an author may do, which a UI can then be built on without
re-deriving those rules.

That is what this report covers.

---

## Files added

```text
app/Modules/Contribution/Contracts/ContributorServiceInterface.php
app/Modules/Contribution/Service/ContributorService.php
app/Modules/Contribution/ContributionServiceProvider.php
app/Modules/Contribution/Exceptions/NotYourContentException.php
app/Modules/Contribution/Exceptions/ContentNotEditableException.php

app/Modules/Api/Http/Controller/MeController.php

tests/Integration/Modules/Contribution/ContributorServiceTest.php
```

## Files modified

```text
app/bootstrap.php                                     provider registered
app/Modules/Api/ApiServiceProvider.php                6 /me routes
app/Modules/Content/Repository/PdoContentRepository.php  `statuses` criterion
```

---

## Ownership

Tables: none. Routes: 6 under `/api/v1/me`.

## Contracts provided

`ContributorServiceInterface`.

## Contracts consumed

`ContentServiceInterface`, `ContentQueryServiceInterface`,
`EditorialWorkflowInterface`, `ActorProviderInterface`.

---

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/me/content` | my editable work |
| GET | `/api/v1/me/content/published` | my published work |
| POST | `/api/v1/me/content` | create a draft owned by me |
| PATCH | `/api/v1/me/content/{id}` | edit my own draft |
| POST | `/api/v1/me/content/{id}/submit` | submit or resubmit |
| GET | `/api/v1/me/content/{id}/history` | editorial history of my piece |

**There is no publish endpoint here**, deliberately. Publishing is an editorial action
that requires `content.publish`, which a contributor does not hold. Adding one would be
exactly the "separate publishing logic" the task pack forbids.

---

## The rules, and why each exists

| Rule | Reason | Failure mode prevented |
|---|---|---|
| Ownership is checked against `author_id`; a mismatch is a **404** | A 403 would confirm someone else's draft exists | Leaking unpublished work |
| Editable only in `draft` / `revision_requested` | Editing after submission means the reviewer reads something different from what was submitted | Reviewing one thing, publishing another |
| `author_id`, `status`, `published_at`, `scheduled_at` are stripped from any create/update payload | A contributor must not file work under another name, nor move state through an edit | Privilege escalation via mass assignment |
| Submission delegates to `EditorialWorkflowInterface::transition()` | Exactly one place knows whether a transition is legal | A second transition table drifting from the first |
| `canTransition()` is asked before submitting | The same target serves `draft → submitted` and `revision_requested → submitted`; asking the state machine keeps this class correct if the machine changes | Hard-coded assumptions rotting |
| Listing filters by author **in the query** | Post-filtering would load every author's rows first | Reading other tenants'/authors' data at all |

## No separate publishing logic

Every state change goes through `EditorialWorkflowInterface`. This module adds **zero**
transitions of its own:

```text
submit()  →  canTransition(current, submitted)  →  transition(...)
```

`revision_requested → submitted` is the same call, because the workflow already allows
that edge for the author's `workflow.submit` capability. Resubmit is not a second code
path.

---

## Media

`featured_media_id` is accepted on create and update, and `media.read` / `media.upload`
are already held by the author and contributor roles, so the media library is reachable
through the existing `/api/v1/media` endpoints. No new media plumbing was required —
which is the point of "must reuse MediaService".

---

## Tests added

`ContributorServiceTest` — 16 tests:

- only `draft` and `revision_requested` are editable
- creating a draft forces ownership and draft status (mass-assignment guard)
- `myDrafts` lists only my own work
- published work appears in the published list
- own draft is editable; ownership and status cannot be changed through an edit
- another author's draft is a 404; a nonexistent row is also a 404
- editing after submission is refused
- submitting moves to `submitted`
- submitting someone else's draft is a 404
- a revision-requested piece can be edited and resubmitted
- a contributor cannot publish
- history of my own piece is visible
- `isEditable` reflects ownership and status
- cross-site drafts are invisible

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (1022 tests, 3141 assertions)
```

Baseline 1006 → 1022: **+16 tests**, none broken.

---

## Verified over real Apache HTTP

```
GET   /api/v1/me/content              (anonymous)  401
POST  /api/v1/me/content                           201  status=draft, author_id set
GET   /api/v1/me/content                           200
PATCH /api/v1/me/content/5                         200  title changed
POST  /api/v1/me/content/5/submit                  200  status=submitted
PATCH /api/v1/me/content/5                         409  CONTENT_NOT_EDITABLE
GET   /api/v1/me/content/5/history                 200
```

The 409 is the rule that matters: after submission the author is locked out until an
editor requests a revision.

---

## Known limitations

1. **No HTML UI.** This is the honest gap. The task pack calls 14C a dashboard; the
   repository has no presentation layer, so building one means inventing layout, forms,
   CSRF wiring and error rendering from nothing. The rules and endpoints a UI would call
   are complete and tested.
2. **No revision-request endpoint for the author** — that is an editor action and lives
   in the editorial workflow (Agent 07). The author consumes it by resubmitting.
3. **No draft autosave on this surface.** `/api/v1/content/{id}/autosave` exists from
   Agent 13 and serves the same purpose; a contributor-facing alias was not added to
   avoid two ways to do one thing.
4. **`myDrafts` returns summaries**, not full entities, so a list does not load every
   block payload. An editor opening one item fetches it from `/api/v1/content/{id}`.
5. **No pagination totals** on `/me/content`; `limit` and `offset` are echoed instead.
6. **No per-author draft quota or rate limit.**
7. **Deleted (trashed) content is excluded** from both lists, so an author cannot see or
   restore their own trashed work through this surface.

---

## CONTRACT_CHANGE_REQUEST

none

`PdoContentRepository::buildWhere()` gained a `statuses` criterion — additive, Content
owns that table, and no frozen contract changed.

---

## Ready to merge

yes for the rules and endpoints. The dashboard itself remains open, and it is the one
item of 14C that is genuinely blocked on a UI layer that does not exist yet.
