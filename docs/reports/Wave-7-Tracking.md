# Wave 7 Tracking — Agent 13 REST API + Agent 14 Extensibility

Controller log. This wave is **NOT closed**: Agent 13 is partial and Agent 14 was not
started. The reason is recorded below rather than papered over.

## Pre-flight

- Baseline before wave: `839 tests, 2726 assertions` — green.
- Spec read: task pack lines 1697–1824 (Agent 13), 1825–2095 (Agent 14).

## Scope decision

Agent 13 as specified is very large: auth, content, posts, pages, workflow,
revisions, autosave, taxonomies, media, SEO, redirects, comments, search, settings,
plugins, themes, ads, notifications, sites. Agent 14 then adds a plugin system and
product features.

Delivering both in one pass to the standard of the earlier waves was not realistic,
and a broad shallow implementation would have been worse than a narrow solid one.
The choice made:

**Build the API infrastructure completely, then complete every endpoint group whose
module already exists.**

Infrastructure is what makes the remaining groups mechanical: middleware, resource
pattern, validator, envelope handling and the route-registration helper are all done
and tested. Agent 13 was then finished in two passes — Auth/Content/Workflow/
Revisions/Autosave/Taxonomy, then Search/Media/SEO/Redirects/Settings/Themes/Users
(see `Agent-13-Completion-Report.md`).

Agent 14 was started after Agent 13 was complete, and **partially** finished: 14B
Comments, 14F Search and 14G Analytics are done (see
`Agent-14-Implementation-Report.md`); 14A Plugin platform, 14C Frontend submission,
14D Advertising and 14E Notifications remain.

14A was deliberately deferred rather than rushed: a plugin API designed in a hurry
turns every hook name and manifest field into a compatibility promise. 14C is a UI
surface and the repository has no UI layer yet. 14D and 14E are feasible but were left
for a dedicated pass rather than half-built (an outbox without its cron is an
unbounded table).

Two defects surfaced in that work:

- **FULLTEXT silently returned zero rows.** This host sets
  `innodb_ft_max_token_size = 10` (default 84), so every word longer than ten
  characters is absent from the index — "Extraordinary" was unsearchable with no
  error. The Search module now only trusts the index when the token window allows it,
  and reports which strategy ran in `meta.strategy`.
- **A new capability needed a re-seed.** Adding `comment.moderate` to the catalogue
  left existing databases at `403` until `RoleService::seedDefaults()` ran again. It
  is idempotent, and that is now documented as the upgrade path.

## Endpoint groups that cannot be built

Four task-pack groups have no module or table behind them, so building them would mean
inventing schema in this wave:

| Group | Missing |
|---|---|
| `comments` | no module, no table |
| `ads` | no module, no table (Agent 14) |
| `notifications` | no module, no table (Agent 14) |
| `plugins` | no plugin system (Agent 14) |

`sites` (super-admin) is a separate decision: **no capability spans tenants**, and one
would violate the rule that Site A must never read Site B data. Adding `site.manage`
here would be scope expansion, so it is left out deliberately rather than forgotten.

## Ordering

Only Agent 13 was touched, so G2 (parallel within a wave) has nothing to apply to.
G7 (no cross-wave parallelism) held: Wave 6 and its addenda were complete first.

## Defects found during the wave

| # | Defect | Severity | Fix |
|---|---|---|---|
| 1 | `Router::run()` never exposed captured route parameters to handlers, so no controller could read `{id}` | **critical** — every resourceful route was impossible | parameters bound as `route.<name>` request attributes; 6 regression tests |
| 2 | `/api/v1/posts` and `/api/v1/pages` returned **all** content, because both aliased `index` which reads `?type=` | **high** — a "posts" endpoint returning pages | dedicated `posts()`/`pages()` actions pinning the type; `?type=` cannot override |
| 3 | Draft access was gated on an invented capability `content.read_private` that does not exist, so drafts were permanently 404 even for administrators | **high** — silent feature loss | use the real `content.read` grant, as a named constant with the reasoning |
| 4 | 422 on `POST /auth/login` over curl | **not a bug** | PowerShell stripped the quotes from `-d '{...}'`, producing invalid JSON; `--data-binary @file` returns 200 |

Defect 2 is the instructive one: the unit of behaviour I first tested was `index`, and
`index` was correct. Only requesting the real URLs over HTTP exposed that the aliases
were wired to the wrong action.

## Gate results

| Gate | Result | Evidence |
|---|---|---|
| Full suite green | PASS | `OK (881 tests, 2841 assertions)` |
| No pre-existing test red | PASS | all 839 prior tests still pass |
| Frozen contracts untouched | PASS | API consumes; introduces none |
| Envelopes match contract | PASS | `"meta":{}` asserted; error has no `status` key |
| No `site_id` from client | PASS | test passes another tenant's id and asserts its content is absent |
| Cross-site 404 | PASS | tested for read and write |
| No private field leak | PASS | no `$2y$` in any auth response |
| Wiring | PASS | 24 API routes registered; real composition root used in tests |
| App serves HTTP | PASS | 10 endpoints verified against Apache |
| **Wave complete (G4)** | **FAIL** | Agent 13 partial, Agent 14 not started |

## What "partial" means for Agent 13

Delivered:

```text
auth        login, logout, me, forgot-password, reset-password
content     list, posts, pages, show, create, update, trash
workflow    transition, allowed transitions, history
revisions   list, snapshot
autosave    update-without-publishing
taxonomy    list terms, create term, delete term, content terms read/sync
```

Not delivered (listed in the report's Known limitations):

```text
media, seo, redirects, comments, search, settings, plugins, themes,
ads, notifications, sites, users/roles, revision restore, restore-from-trash
```

## Open security item — CLOSED after the wave

**The API is cookie-authenticated, and originally had no CSRF protection on
mutations.** A cross-origin `POST /api/v1/content` would have succeeded if the victim
had a session.

Root cause: the frozen `CsrfTokenManagerInterface` shipped from Agent 01 with **no
implementation and no container binding**, so nothing was protecting anything.

Closed in `CSRF-Hardening-Report.md`:

- `CsrfTokenManager` implemented (32 random bytes, `hash_equals`, session-bound,
  rotatable) and bound lazily in Core.
- `CsrfMiddleware` requires the token on every non-safe API request, applied from a
  single `$write` list so a new mutation route cannot be added without it.
- Login is protected too, because login CSRF is a real attack.
- The check runs before authentication, so a forged anonymous request does not learn
  that the endpoint expects a session.
- Editorial reads (`/transitions`, `/history`, `/revisions`) now require
  `content.read`.

A real defect surfaced during that work: the first implementation answered `419`, and
**Apache translates an unassigned status code to 500**, so a forged request would have
arrived as a server error. Switched to `403` with the same specific error code. Only
HTTP verification caught it — the in-process status was correct.

Still open after this: **no rate limiting** on login or password reset, and
`SameSite` on the session cookie is not verified.

## Notes for the next wave

1. **Close the CSRF gap** (see above) before adding more mutation endpoints.
2. Make `GET /content/{id}/transitions` and `/history` require `content.read`; they
   currently expose editorial state anonymously.
3. The remaining endpoint groups are now mechanical: bind a resource, a controller
   action, and a `$this->route(...)` line. Media and SEO are the highest-value next
   because the services and tables already exist.
4. Agent 14 (plugin system) should start only after the API groups that plugins would
   extend are stable — a plugin API designed against a half-built surface will need
   rewriting.
5. Consider extracting the `route()` helper from `ApiServiceProvider` into a small
   `RouteCollector` so the provider does not grow into a routing table.

## Outcome

**Wave 7 incomplete.** Agent 13 accepted as a partial vertical with 42 tests and no
regressions; Agent 14 not started.

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (881 tests, 2841 assertions)
```

Baseline 839 → 881: **+42 tests**, none of the previous 839 broken.

| Suite | Tests | Assertions |
|---|---|---|
| Unit | 328 | 795 |
| Integration | 385 | 848 |
| Contract | 58 | 794 |
| Security | 103 | 389 |
| Performance | 7 | 15 |
| **Total** | **881** | **2841** |
