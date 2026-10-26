# Admin UI — Implementation Report

The platform had no interface. This adds one: sign in, a dashboard, a content list, an editor
with a block form, and the review and publish steps. The editorial loop is now usable by a
person, not only by `curl`.

Scope was agreed before any code: phases 1-4, server-rendered forms, calm visual direction.
Design document: `docs/plans/Admin-UI-Design.md`.

---

## What a person can do now

```text
/admin/login          sign in (rate limited, CSRF, uniform failures)
/admin                dashboard: your drafts, the review queue, your published work
/admin/content        list, filter by status, search by title
/admin/content/new    write a draft
/admin/content/{id}   edit it, classify it, set its search appearance
/admin/content/{id}/review   read it and approve or ask for a revision
```

Every action a contributor needs is a button, and every button is one the server will accept.

---

## Files

```text
app/Modules/Admin/
  AdminServiceProvider.php
  Http/AdminController.php                       shared screen behaviour
  Http/Middleware/AdminContextMiddleware.php     tenant + actor, both derived
  Http/Middleware/AdminAuthMiddleware.php        sign-in gate, CSRF, security headers
  Http/Middleware/AdminErrorMiddleware.php       exceptions as pages, not JSON
  Http/Controller/AuthController.php
  Http/Controller/DashboardController.php
  Http/Controller/ContentController.php
  Support/AdminView.php                          two-pass render, isolated templates
  Support/BlockFormCompiler.php                  form fields <-> content_json
  Resources/views/{layout,login,error,dashboard}.php
  Resources/views/content/{index,form,review}.php
public/assets/admin.css                          one hand-written stylesheet
tests/Support/AdminTestCase.php                  form-post harness
```

Modified: `app/bootstrap.php` (register the provider after Theme), and the public catch-all
regex in `ThemeServiceProvider` (below).

---

## Decisions worth knowing

**The admin is not themeable.** Templates live in the application and reuse
`ViewRendererInterface`, which means admin templates get the same guarantee public themes do:
only `$view` and `$renderer`, no container, no database. A tenant theme can never restyle or
break the dashboard.

**Buttons are computed, not written.** The review screen builds its actions from
`allowedTransitions()` filtered by `requiredCapability()`. The interface cannot offer a step the
server will refuse, because both read the same source of truth. A test asserts a contributor is
never shown a publish control.

**Errors are pages.** The global `ExceptionMiddleware` renders JSON, which is right for the API.
`AdminErrorMiddleware` sits inside the route chain and converts to an HTML page, so a mistyped
admin URL is a 404 page rather than `{"error":...}`.

**One trailing block row.** Adding a block needs no round trip and no JavaScript: the editor
always renders one blank paragraph after the real blocks, and the compiler drops it when empty.
Removing a block is clearing its text. Changing a block's type reloads the row with the right
fields. Clunky compared with a JS editor, but it works with JavaScript disabled and it is
testable over HTTP.

**Flash messages are read once.** Success is `POST -> 303 -> GET` with a one-shot message, so a
refresh never re-submits a publish.

---

## Two defects the build exposed

**1. Route parameters were read from the wrong attribute.** The router binds captured params as
`route.<name>`, and the admin base controller read `attribute('<name>')`. Every screen with an
id in the path returned 404. Caught by the first real browser-shaped request, not by a unit
test, because the unit boundary ended above the controller.

**2. The review history was read as rows, and the workflow returns entities.** `history()`
returns `WorkflowLog` objects with camelCase properties, so the first version rendered
`start -> ` with empty fields. Only visible in the HTML.

**Also fixed on the way:** `AdminAuthMiddleware` initially skipped CSRF for `/admin/login`
because that path is the anonymous one. That would have left the login form itself CSRF-exposed
-- session fixation by another name. The middleware now requires a token for the login POST
while still requiring no session.

**And hardened:** the public catch-all was `/{path:(?!api/|admin/).*}` which excludes `admin/`
but not bare `/admin`. Relying on provider boot order for a security-relevant path is fragile,
so the regex is now `(?!api/|admin/|admin$)`.

---

## Tests

| File | Tests | Covers |
|---|---|---|
| `AdminAuthTest` | 13 | login page, uniform failures, `next` sanitisation, rate limit, logout via POST only, every route gated |
| `AdminAuthorizationTest` | 10 | own-vs-all scope, review gate, publish refusal, cross-tenant 404, unknown id 404 |
| `AdminSecurityTest` | 7 | `no-store`/`DENY`/`nosniff`/`same-origin`, missing and forged tokens, title escaping, blocks stored as data |
| `AdminContentFlowTest` | 5 | the whole loop through HTML, buttons match permissions, blank block dropped, terms, SEO |
| `BlockFormCompilerTest` | 28 | JSON <-> form round trip, empty/unknown/malformed input |

```text
PHPUnit 11.5.57 - PHP 8.3.6
OK (1195 tests, 3808 assertions)
```

Baseline before this work: 1132 tests. +63, none broken.

`AdminContentFlowTest` is deliberately a second end-to-end test rather than a reuse of the JSON
one (`PublishFlowTest`): the API and the forms share services but not controllers, and the point
is that both reach the same published article.

---

## Verified by hand, over Apache

```text
GET  /admin/login                 200, CSRF field present
POST /admin/login                 303 -> /admin
GET  /admin                       200, dashboard with drafts and "New article"
GET  /admin/content/new           200
POST /admin/content               303 -> /admin/content/7
GET  /admin/content/7             200, title and blocks round-tripped
POST .../submit                   303, status submitted
POST .../transition to=review     303
POST .../transition to=approved   303
POST .../transition to=published  303
GET  /blog/harbour-report         200, published, X-Cache MISS then HIT
GET  /admin/content/7/review      200, history: draft -> submitted -> review -> approved -> published
GET  /assets/admin.css            200
```

The test article was removed afterwards. Note what that revealed: deleting the row with SQL left
`/blog/harbour-report` answering **200** from the page cache. Cache invalidation is
event-driven, so a change made behind the application's back is invisible to it. Flushing the
site cache through the application restored the 404. That is the cache working as designed, and a
warning for anyone scripting maintenance against the database directly.

---

## Known limitations

1. **No JavaScript at all.** Phase 5 (live preview, drag-to-reorder, unsaved-changes guard) was
   not built. Changing a block's type requires saving first.
2. **No media picker.** The image block takes a URL and the featured-image field is not exposed
   in the editor. `POST /api/v1/media` exists, but the library screen does not.
3. **No scheduling UI.** `EditorialWorkflowInterface::schedule()` exists and the cron publishes
   due content; there is no date picker.
4. **No user, role, site or plugin management.** Out of scope by the design document, and still
   out of scope: those need a super-admin surface with a cross-tenant capability that does not
   exist.
5. **`/admin/media`, `/admin/terms` are not built** as separate screens; terms are edited inside
   the article editor.
6. **Trash is reachable only through the controller**, with no screen to restore from.
7. **No pagination links beyond the content list.**
8. **The dashboard shows no counts**, only lists, so "3 drafts" is not displayed.

---

## CONTRACT_CHANGE_REQUEST

none

No frozen contract was touched. `app/bootstrap.php` gained one provider registration and
`ThemeServiceProvider` one regex alternative.

---

## Ready to merge

yes
