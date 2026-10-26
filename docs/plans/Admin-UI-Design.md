# Admin UI — Design Plan

Status: **implemented, phases 1-4**. Decisions below are the ones that were built; the result
is documented in `docs/reports/Admin-UI.md`.

Scope: admin login and the contributor/editor dashboard.

---

## 1. What exists, and what it constrains

Four facts found by reading the code, not assumed:

| Fact | Consequence |
|---|---|
| The public catch-all is `/{path:(?!api/|admin/).*}` | `/admin/` was reserved from the start. This was designed for. |
| `ViewRenderer::render()` takes an **absolute path** and gives the template only `$view` and `$renderer` | reusable outside the theme, with the same "no container, no DB in template" guarantee |
| `ViewModel::e()` escapes | escaping has one entry point; templates never call `htmlspecialchars` themselves |
| `EditorialWorkflowInterface` exposes `allowedTransitions()` and `requiredCapability()` | the UI can derive its buttons from the same rule the API enforces, instead of duplicating it |

The last one is the most valuable. A review screen whose buttons are computed from
`allowedTransitions()` + `requiredCapability()` cannot drift from what the server permits.

Existing services are already sufficient — no new application logic is needed:

```text
ContributorServiceInterface  myDrafts, myPublished, createDraft, updateOwn, submit, history, isEditable
ContentServiceInterface      create, update, get, getBySlug, trash, purge, setStatus
EditorialWorkflowInterface   transition, allowedTransitions, requiredCapability, schedule, history
TaxonomyServiceInterface     terms, sync
MediaServiceInterface        upload, listForSite, update, delete
SeoServiceInterface          metaFor, store
```

---

## 2. Decisions

### D1 — Server-rendered PHP. No build step, no bundler, no SPA.

Production forbids Node.js. A build step would produce artifacts that cannot be rebuilt on
the server, which turns every future tweak into "install Node on your laptop first". The admin
is plain PHP templates plus one CSS file and (optionally) one small ES module loaded with
`<script type="module">` — no compilation.

This is not a compromise. A CMS admin is forms and lists; the parts that benefit from
JavaScript are the block editor and unsaved-changes warnings, and both work without it.

### D2 — One new module: `app/Modules/Admin/`

It owns HTTP controllers, views and the layout, and it calls **services only**. It must never
reach a repository or a table, exactly like a controller in any other module.

Alternative considered: admin controllers inside each module (`Modules/Content/Http/Admin/…`).
More faithful to module ownership, but it scatters one screen's data across four directories
and makes the shared shell awkward. The admin is a presentation layer over several modules, so
it is one module that consumes several.

The dependency direction stays intact:

```text
Admin Controller → Application Service → Domain → Repository Interface → …
```

### D3 — The admin layout is **not** themeable

Templates live in `app/Modules/Admin/Resources/views/`, CSS in `public/assets/admin.css`.

A tenant's theme must not be able to restyle or break the dashboard, and theme templates are
vetted for "does not touch the database", not for "safe as an admin surface". Making the admin
themeable would also mean every theme must ship a usable admin, which no theme author asked
for.

### D4 — Auth reuses what exists

| Concern | Reuse |
|---|---|
| session | `SessionInterface` (file-backed, already swapped for `ArraySession` in tests) |
| credentials | `AuthenticationService::login` / `logout` — already regenerates the session id |
| CSRF | `CsrfTokenManagerInterface`, same as the API |
| rate limit | `RateLimiter`, 5 attempts / 300s, same `keyFor()` as the API login |
| authorization | `ActorInterface::can()` with the existing capability catalogue |

`/admin/logout` is **POST only**. A GET logout can be triggered by an image tag on any page.

### D5 — Every mutation is POST → redirect → GET

No admin mutation renders a response directly. Refresh must never resubmit a publish.
Validation failures are the exception: those re-render the form with the submitted values and
the errors, because throwing the user's typing away on a typo is hostile.

Successes carry a one-shot flash message in the session.

### D6 — The block editor works without JavaScript

`content_json` is an array of blocks. The form encodes them as ordinary fields:

```html
<input name="blocks[0][type]" value="heading">
<input name="blocks[0][data][text]" value="Harbour Report">
<textarea name="blocks[1][data][text]">The tide came in early.</textarea>
```

Add, remove and move are submit buttons that name their own action, so reordering works with
JavaScript disabled. A small ES module then layers on: live preview, drag-to-reorder, and a
"you have unsaved changes" guard.

The conversion between `content_json` and form fields is the one piece of genuinely tricky
logic here, so it lives in its own class (`BlockFormCompiler`) with unit tests, rather than
inside a template.

**Alternative rejected:** a JS block editor as the only path. It would be faster to make
pretty and worse to own: it cannot be tested through HTTP, it breaks with JS off, and it puts
the block schema in two languages.

### D7 — Validation happens in the service layer, not the controller

Controllers translate HTTP into service calls and service exceptions into form errors. The
rules for "what makes a valid draft" must be identical whether the article arrives through the
API or through a form; duplicating them is how the two drift.

---

## 3. Screens

```text
GET  /admin/login             login form
POST /admin/login             authenticate, rate limited
POST /admin/logout            destroy session (CSRF)

GET  /admin                   dashboard
GET  /admin/content           list: mine / to review / all (by capability)
GET  /admin/content/new       create draft
GET  /admin/content/{id}      editor
GET  /admin/content/{id}/review   review screen (reviewer/editor)
GET  /admin/media             library
GET  /admin/media?pick=1      library in picker mode (for featured image)

POST /admin/content/{id}/save       create or update
POST /admin/content/{id}/submit     submit for review
POST /admin/content/{id}/transition one workflow step (validated against allowedTransitions)
POST /admin/content/{id}/terms      replace the attached set
POST /admin/content/{id}/seo        store SEO overrides
POST /admin/content/{id}/delete     trash
POST /admin/media/upload            multipart upload
```

### Login

```text
┌──────────────────────────────────────────────┐
│                                              │
│              Site name                       │
│                                              │
│   ┌────────────────────────────────────┐     │
│   │ Username or email                  │     │
│   └────────────────────────────────────┘     │
│   ┌────────────────────────────────────┐     │
│   │ Password                        👁 │     │
│   └────────────────────────────────────┘     │
│   ┌────────────────────────────────────┐     │
│   │ Sign in                            │     │
│   └────────────────────────────────────┘     │
│                                              │
└──────────────────────────────────────────────┘
```

One error message for "wrong password" and "no such account", so the form does not enumerate
users. After 5 failures, the same `429` path as the API, rendered as a form-level message.

### Dashboard

```text
┌ Site name ──────────────────── admin ▾ ──────┐
│ Content   Media   Review                     │
├──────────────────────────────────────────────┤
│  My drafts (3)          Waiting for review (1)│
│  ┌────────────────────────────────────────┐  │
│  │ Harbour Report        draft   2h ago   │  │
│  │ Tide Tables           draft   1d ago   │  │
│  └────────────────────────────────────────┘  │
│                                              │
│  Recently published                          │
│  ┌────────────────────────────────────────┐  │
│  │ Hello from the renderer   published    │  │
│  └────────────────────────────────────────┘  │
└──────────────────────────────────────────────┘
```

The dashboard shows what the person can act on, not statistics. A contributor does not need a
chart of page views; they need the draft they abandoned yesterday.

### Content editor

```text
┌ ← Back to content          draft · not public  ┐
│ Title ────────────────────────────────────────│
│ [Harbour Report                            ]  │
│ Slug: /blog/harbour-report                    │
│                                               │
│ ┌ Content ──────────────────────────────────┐ │
│ │ [Heading ▾]                            ×  │ │
│ │ [Harbour Report                        ]  │ │
│ │ [Paragraph ▾]                          ×  │ │
│ │ [The tide came in early.               ]  │ │
│ │ [+ Add block]                             │ │
│ └───────────────────────────────────────────┘ │
│                                               │
│ ┌ Excerpt ──────────────────────────────────┐ │
│ ┌ Featured image ──────── [ Choose ] ───────┐ │
│ ┌ Categories ───────────────────────────────┐ │
│ ┌ SEO ─ meta title, description, robots ────┐ │
│                                               │
│ [Save draft]   [Submit for review]            │
└───────────────────────────────────────────────┘
```

The right-hand column carries metadata (terms, SEO, featured image); the main column carries
the content. Collapsible sections keep the page short without hiding anything permanently.

Buttons are computed:

| Actor holds | Sees |
|---|---|
| `workflow.submit` and editable | *Save draft*, *Submit for review* |
| `workflow.review` | *Request revision*, *Approve* |
| `content.publish` | *Publish*, *Schedule* |
| none | read-only view |

### Review screen

Shows the rendered content, the revision note from the previous round, the workflow history,
and only the transitions `allowedTransitions()` permits. A reviewer never sees a *Publish*
button they cannot use.

---

## 4. Security

Each line is a test, not an intention.

| Risk | Mitigation |
|---|---|
| unauthenticated access | every route except login requires a session; tested per route |
| CSRF | every POST verified by `CsrfTokenManager`; tested per action |
| privilege escalation | capability checked per action; transitions use `requiredCapability()` |
| cross-tenant access | site resolved from host; a foreign content id is 404, never 403 |
| XSS | templates only output through `$view->e()`; a test posts `<script>` into a title and asserts it is escaped |
| shared-machine history | `Cache-Control: no-store` on every admin response |
| clickjacking | `X-Frame-Options: DENY` |
| MIME sniffing | `X-Content-Type-Options: nosniff` |
| referrer leakage | `Referrer-Policy: same-origin` |
| brute force | `RateLimiter`, 5/300s, shared with the API login |
| upload as code | existing media validation plus the uploads `.htaccess` guard |

---

## 5. Routing detail to fix

The catch-all `/{path:(?!api/|admin/).*}` excludes `admin/` but **not bare `/admin`**. The
admin provider will register `/admin` explicitly and boot before Theme, so the exact route
wins — but relying on boot order for a security-relevant path is fragile. The regex becomes:

```php
'/{path:(?!api/|admin/|admin$).*}'
```

A test asserts that `GET /admin` with no route registered does not fall through to the public
renderer.

---

## 6. Testing

| Test file | Covers |
|---|---|
| `AdminAuthTest` | login page renders; wrong password rejected; rate limit trips; logout requires POST + CSRF; every admin route redirects to login when anonymous |
| `AdminAuthorizationTest` | contributor cannot reach review or publish; editor can; cross-tenant id is 404 |
| `AdminContentFlowTest` | the mandated scenario through HTML: login → new → save → submit → review → revision → edit → resubmit → approve → publish → visible publicly |
| `AdminSecurityTest` | CSRF on every POST; `no-store`/`DENY`/`nosniff` headers; a `<script>` title is escaped |
| `BlockFormCompilerTest` (unit) | JSON → form fields → JSON round trip, including empty, unknown block types, and missing data keys |

The `AdminContentFlowTest` is deliberately a second end-to-end test rather than a reuse of the
JSON one: the API path and the form path share services but not controllers, and the whole
point is that both reach the same published article.

---

## 7. Phases

Each phase is shippable and testable on its own.

| Phase | Delivers | Rough size |
|---|---|---|
| 1 | module skeleton, layout, CSS, login/logout, dashboard | largest single chunk |
| 2 | content list, editor (create/update draft, block forms) | large |
| 3 | terms, featured image picker, SEO panel | medium |
| 4 | submit, review, publish, schedule | medium |
| 5 | a11y pass, empty states, JS enhancements, unsaved-changes guard | small |

Phases 1–2 are what "login admin + dashboard kontributor" means. 3–4 complete the editorial
loop. 5 is polish that can be deferred without leaving anything broken.

---

## 8. Explicitly out of scope

Written down so it does not creep in later:

- user, role and permission management UI
- site creation / site switcher / super-admin surface
- plugin management UI
- theme editor
- WYSIWYG rich text (blocks are the model; no contenteditable)
- comment moderation UI
- analytics or advertising dashboards
- a design system, component library, or CSS framework
- JavaScript build tooling of any kind

Each is a real feature that a production CMS eventually needs. None is needed to write and
publish an article, and this document is about getting that loop closed.

---

## 9. Decisions taken

Answered before implementation, and implemented as chosen:

1. **Block editor baseline** — server-rendered forms with optional JS enhancement. No JS was
   written in this pass; the forms work completely without it.
2. **Delivery boundary** — phases 1-4: the whole editorial loop through HTML, including the
   end-to-end test. Phase 5 (JS enhancements, unsaved-changes guard) is deferred.
3. **Visual direction** — calm and spacious: generous spacing, one readable column, colour
   only for meaning (status, error). One hand-written stylesheet, no framework.
