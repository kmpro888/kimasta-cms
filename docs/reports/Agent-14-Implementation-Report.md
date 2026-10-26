# Agent 14 — Extensibility & Product Features — Implementation Report

Agent: Agent 14 (split per the task pack, which states it "may be split into
sub-agents")
Status: DONE for six of seven sub-agents; 14C's HTML UI remains open.

| Sub-agent | Module | State |
|---|---|---|
| **14A** Plugin platform | `CMS\Modules\Plugin` | done — see `Agent-14A-Plugin-Platform-Report.md` |
| **14B** Comments | `CMS\Modules\Comment` | done |
| **14C** Frontend submission | `CMS\Modules\Contribution` | rules + endpoints done; **no HTML UI** — see `Agent-14C-Frontend-Submission-Report.md` |
| **14D** Advertising | `CMS\Modules\Advertising` | done — see `Agent-14D-Advertising-Report.md` |
| **14E** Notifications | `CMS\Modules\Notification` | done — see `Agent-14E-Notifications-Report.md` |
| **14F** Search | `CMS\Modules\Search` | done |
| **14G** Analytics integration | `CMS\Modules\Analytics` | done |

The subset was chosen because these three are self-contained, need no new cross-module
contract, and each closes a gap that was visible in the running product: search was a
`LIKE` scan, comments did not exist at all, and there was no way to install a tracker.

---

## Files added

```text
app/Modules/Comment/Contracts/CommentServiceInterface.php
app/Modules/Comment/Entity/Comment.php
app/Modules/Comment/Exceptions/CommentDepthExceededException.php
app/Modules/Comment/Exceptions/CommentNotFoundException.php
app/Modules/Comment/Exceptions/CommentsClosedException.php
app/Modules/Comment/Repository/CommentRepositoryInterface.php
app/Modules/Comment/Repository/PdoCommentRepository.php
app/Modules/Comment/Service/CommentService.php
app/Modules/Comment/CommentServiceProvider.php
app/Modules/Comment/README.md

app/Modules/Search/Contracts/SearchServiceInterface.php
app/Modules/Search/Service/SearchService.php
app/Modules/Search/SearchServiceProvider.php
app/Modules/Search/README.md

app/Modules/Analytics/Contracts/AnalyticsServiceInterface.php
app/Modules/Analytics/Service/AnalyticsService.php
app/Modules/Analytics/AnalyticsServiceProvider.php
app/Modules/Analytics/README.md

app/Modules/Api/Http/Controller/CommentController.php

database/migrations/20261026001000_add_content_fulltext_index.php
database/migrations/20261026001100_create_comments_table.php

tests/Support/AppTestCase.php
tests/Integration/Modules/Search/SearchServiceTest.php
tests/Integration/Modules/Comment/CommentServiceTest.php
tests/Unit/Modules/Analytics/AnalyticsServiceTest.php
```

## Files modified

```text
app/bootstrap.php                                     three providers registered
app/Modules/Identity/Support/CapabilityCatalogue.php   comment.moderate added
app/Modules/Content/Repository/PdoContentRepository.php  fulltext strategy + word-wise LIKE
app/Modules/Api/ApiServiceProvider.php                 comment routes, Search injected
app/Modules/Api/Http/Controller/ContentController.php  search delegates to Search module
tests/Support/ApiTestCase.php                          split: now extends AppTestCase
tests/Integration/Modules/Api/ApiExtrasTest.php        +10 comment tests
tests/Support/ModuleDatabaseTestCase.php               two migrations added
```

---

## Contracts provided

`CommentServiceInterface`, `SearchServiceInterface`, `AnalyticsServiceInterface`.

## Contracts consumed

Content (`ContentServiceInterface`, `ContentQueryServiceInterface`), Identity
(`UserServiceInterface`, `RoleServiceInterface`), Site (`SettingsServiceInterface`),
plus `EventDispatcherInterface` and `ClockInterface`.

---

## Database migrations

| Migration | Effect |
|---|---|
| `20261026001000_add_content_fulltext_index.php` | `FULLTEXT KEY ft_contents_search (title, content_plain)` |
| `20261026001100_create_comments_table.php` | `comments` |

Both verified forward and rollback. The FULLTEXT migration checks
`information_schema.statistics` before altering, because MySQL 8 has no
`ADD FULLTEXT IF NOT EXISTS`; re-running is a no-op rather than an error.

---

## Events

| Event | Emitted by |
|---|---|
| `comment.created` | after a comment is stored |
| `comment.approved` | only on a real pending→approved transition |
| `comment.deleted` | after a hard delete |

All three are emitted **after** the write commits, so a listener never observes a row
that was rolled back.

---

## The interesting defect: FULLTEXT silently returns nothing

The search implementation was written the obvious way:

```sql
MATCH(title, content_plain) AGAINST ('Extraordinary*' IN BOOLEAN MODE)
```

and returned **zero rows** for a word that was plainly in the title. Three tests
failed, and the cause was environmental:

```text
SHOW VARIABLES LIKE 'innodb_ft_max_token_size';
innodb_ft_max_token_size   10
```

MySQL's default is 84. This installation is configured to 10, so **every word longer
than ten characters is absent from the index**. "Extraordinary" (13),
"documentation" (13) and "unpublished" (11) were unsearchable, with no error at all.
A prefix query for "document" also failed, because the target word was never indexed.

This is the worst failure mode a search box can have: it looks like "no results",
not like a bug.

Two changes came out of it:

1. **The index is only trusted when the token window allows it.**
   `strategyFor()` returns `like` when `innodb_ft_max_token_size < 20`, or when any
   query token falls outside `[min, max]`. On this host the strategy is therefore
   `like` — correct results, slower — and on a default MySQL install it is `fulltext`.
2. **The LIKE fallback became word-wise.** It previously searched the literal phrase,
   so `red boat` matched only those two words adjacent. It now ORs the words, matching
   the boolean-mode semantics, so both strategies agree about the same query.

The API reports `meta.strategy` on every search so an operator can see which path ran.

## Second defect: topic capability needed a re-seed

Adding `comment.moderate` to the catalogue was not enough for an existing database: the
capability row and the role links are created by `RoleService::seedDefaults()`. An
existing site returned `403` until re-seeded. `seedDefaults()` is idempotent
(`capabilityRepository->ensure()`), so re-running it is the upgrade path — and it is
now documented as such rather than left as a surprise.

---

## Tests added

- `SearchServiceTest` (12): strategy selection tied to the server's token window, title
  match, body match, prefix match, very short term, draft exclusion, opt-in
  unpublished, tenant isolation, operator injection, empty term, pagination, API
  strategy report
- `CommentServiceTest` (19): pending default, not in public thread, approve, count
  tracking, trash removes from count, spam distinct from trash, approve-once event,
  reply depth, **bounded nesting**, reply to pending parent refused, cross-article
  reply refused, closed article refused, draft refused, tenant isolation, cross-site id
  rejected, cascade delete, queue filtering, short body rejected, denormalised author
- `AnalyticsServiceTest` (13): disabled, unknown provider, Google, legacy `UA-`, and
  six adversarial cases (quotes, markup, scheme-in-host, non-numeric site id,
  missing id)
- `ApiExtrasTest` (+10): anonymous thread shows approved only, anonymous submit 401,
  authenticated submit 201 pending, short body 422, closed comments 409, moderation
  capability, approve visible in public thread, queue gated, cascade delete,
  cross-site comment 404

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (992 tests, 3087 assertions)
```

Baseline 938 → 992: **+54 tests**, none broken.

---

## Verified over real Apache HTTP

```
GET  /api/v1/search?q=renderer                200   meta.strategy = like
POST /api/v1/content/3/comments               201   status=pending
GET  /api/v1/comments                         200   moderation queue
POST /api/v1/comments/1/approve               200   status=approved
GET  /api/v1/content/3/comments               200   approved comment present
```

The full comment lifecycle — submit, queue, approve, public visibility — works over
HTTP with a real session and CSRF token.

---

## Why 14A, 14C, 14D and 14E were not started

| Sub-agent | Reason |
|---|---|
| **14C Frontend submission** | This is a contributor dashboard — a **UI**. The repository has no admin or author UI at all, so building this means inventing an entire presentation layer (layout, forms, CSRF wiring, error rendering). It also must reuse `ContentService` and `EditorialWorkflow`, which it can — the blocker is UI, not services. |
| **14D Advertising** | Self-contained and feasible (`ads`, `ad_rules`, `AdvertisingServiceInterface`). Not done because the wave was already large, and an ad module with no theme placement wired is half a feature. |
| **14E Notifications** | Feasible and well-supported by the existing event stream. It needs `notifications`, `notification_outbox`, per-event/per-channel switches and a cron outbox processor. Deferred rather than half-built, because an outbox without its cron is an unbounded table. |

None of these is blocked by an architectural problem. They are blocked by the size of
a single pass.

---

## Known limitations

1. **Search has no relevance ranking.** Ordered by publish date; `meta.strategy` tells
   you which path ran. `MATCH` can score, but the score needs to reach the SELECT list
   and a second placeholder, and an incomplete relevance sort is worse than none.
2. **On this host search is always `like`**, i.e. a scan, because
   `innodb_ft_max_token_size` is 10. Correct, not fast. The fix is server
   configuration, and the module now reports which strategy it chose rather than
   hiding it.
3. **Comments have no notification** on submission or approval (14E).
4. **Comments cannot be edited** after submission.
5. **No rate limit** on comment submission or login.
6. **`contents.comment_count` is not stored** — derived on demand. The task pack lists
   "contents.comment_count coordination"; a denormalised column was rejected because it
   drifts, and the count is only read on pages that display it. Stated because it is a
   deliberate divergence from the wording.
7. **Analytics snippet is not wired into the default theme** yet — the contract is
   bound and tested, but no template calls it.
8. **No `sitemap` of comments** and no comment feed.

---

## CONTRACT_CHANGE_REQUEST

none

`comment.moderate` was added to the Identity capability catalogue, and
`PdoContentRepository` gained `search_mode` awareness. Both are additive; no frozen
contract changed and all prior tests remain green.

---

## Ready to merge

yes — for 14B, 14F and 14G. Agent 14 as a whole is **not complete**: 14A, 14C, 14D and
14E remain.
