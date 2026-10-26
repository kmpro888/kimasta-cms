# Agent 13 — REST API — Completion Report

Agent: Agent 13 — REST API
Module: `CMS\Modules\Api`
Status: DONE for every endpoint group whose module exists. Four groups remain impossible because their modules do not exist; recorded below with reasons.

---

## Summary

Completed the endpoint groups the previous pass left open: **search, media, SEO,
redirects, settings, themes, users and roles**. The API now covers every task-pack
group that has a backing module.

Also extracted `Tests\Support\ApiTestCase` so both API suites boot the real composition
root through one harness instead of duplicating it.

`47` API routes, `89` API tests.

---

## Files added

```text
app/Modules/Api/Http/Controller/MediaController.php
app/Modules/Api/Http/Controller/SeoController.php
app/Modules/Api/Http/Controller/RedirectController.php
app/Modules/Api/Http/Controller/SettingsController.php
app/Modules/Api/Http/Controller/ThemeController.php
app/Modules/Api/Http/Controller/UserController.php

app/Modules/Api/Resources/MediaResource.php
app/Modules/Api/Resources/SeoResource.php
app/Modules/Api/Resources/RedirectResource.php
app/Modules/Api/Resources/ThemeResource.php

tests/Support/ApiTestCase.php
tests/Integration/Modules/Api/ApiExtrasTest.php
```

## Files modified

```text
app/Modules/Api/ApiServiceProvider.php                 bindings + 23 new routes
app/Modules/Api/Http/Controller/ContentController.php  search action
tests/Integration/Modules/Api/ApiEndpointTest.php      harness extracted
```

---

## Endpoints added

| Method | Path | Auth | Capability |
|---|---|---|---|
| GET | `/api/v1/search?q=` | – | – (drafts filtered) |
| GET | `/api/v1/media` | yes | `media.read` |
| GET | `/api/v1/media/{id}` | yes | `media.read` |
| PATCH | `/api/v1/media/{id}` | yes | `media.upload` |
| DELETE | `/api/v1/media/{id}` | yes | `media.delete` |
| GET | `/api/v1/content/{id}/seo` | – | `content.read` for drafts |
| PUT | `/api/v1/content/{id}/seo` | yes | `content.update` |
| GET | `/api/v1/redirects` | yes | `settings.read` |
| POST | `/api/v1/redirects` | yes | `settings.update` |
| DELETE | `/api/v1/redirects?source_path=` | yes | `settings.update` |
| GET | `/api/v1/settings` | yes | `settings.read` |
| PUT | `/api/v1/settings` | yes | `settings.update` |
| GET | `/api/v1/themes` | yes | `settings.read` |
| GET | `/api/v1/themes/active` | yes | `settings.read` |
| POST | `/api/v1/themes/{name}/activate` | yes | `themes.manage` |
| GET | `/api/v1/users` | yes | `users.read` |
| POST | `/api/v1/users` | yes | `users.create` |
| GET | `/api/v1/users/{id}` | yes | self, else `users.read` |
| PATCH | `/api/v1/users/{id}` | yes | `users.update` |
| DELETE | `/api/v1/users/{id}` | yes | `users.delete` |
| GET | `/api/v1/users/{id}/roles` | yes | `users.read` |
| PUT | `/api/v1/users/{id}/roles` | yes | `roles.assign` |

---

## Design decisions worth recording

### Settings are an allow-list, not a key-value store

`SettingsController` has an explicit map of writable keys per namespace. A settings
endpoint that accepts any key turns the settings table into an unvalidated,
network-reachable store: a typo creates a junk row, a probing client can enumerate
what is stored, and removing a feature never removes its orphaned settings.

Reading is filtered the same way, so a setting added by a future feature is not
exposed until someone deliberately adds it to the list.

### A user cannot delete themselves

`DELETE /users/{id}` on your own account is a `409`. Nothing about the capability
system prevents an administrator from revoking their own access mid-request, and a
site whose only administrator does that is unrecoverable through the API.

### Media deletion refuses while referenced

`DELETE /media/{id}` returns `409 RESOURCE_IN_USE` unless `?force=1`. Deleting an image
an article uses leaves a broken reference on a live page, and the page cache would
serve it until the next edit.

### Redirect loops get their own error code

A loop returns `409 REDIRECT_LOOP`, not a generic validation failure. "Validation
failed" on a redirect gives an editor nothing to act on; "that would create a loop"
does. The service already had this logic — the controller just had to stop flattening
it.

### Search is honest about ordering

`GET /search` matches title and plain text through the same indexed path the public
page uses, ordered by publish date. There is no relevance scoring, and the code says
so rather than implying otherwise.

### SEO read returns resolved values

`GET /content/{id}/seo` returns the **resolved** metadata including derived defaults,
not the raw overrides. An editor sees exactly what the page will render, which is the
whole point of centralising SEO.

A relative canonical is rejected (`422`): it would render as-is and point at whatever
the current URL happens to be.

---

## Bug found

**`ThemeController::activate()` always reported `active: false`.** The response used the
injected `ThemeResource`, which was constructed with no active name, so the freshly
activated theme came back marked inactive. Fixed by rebuilding the projection with
`ThemeResource::withActive($theme->name)`. Caught by the test asserting `active` is
true after activation.

---

## Tests added

- integration: `ApiExtrasTest` — 39 tests
  - search: title match, missing term 422, drafts excluded
  - media: read capability, metadata shape, no storage path leak, cross-site 404,
    update, empty update 422, delete gated
  - SEO: anonymous read for published, draft 404, overrides stored, relative canonical
    rejected, anonymous write 401
  - redirects: tenant-scoped listing, create + delete, loop 409 with its own code,
    invalid target 422, anonymous create 401
  - settings: read gated, update, key outside allow-list 422, unknown namespace 422
  - themes: list with active flag, active read, activation, unknown theme 404,
    capability enforcement
  - users: list gated, create without hash leak, anonymous 401, duplicate 409,
    invalid email 422, self-read allowed, other-user read 403, cross-site 404,
    self-delete 409, role read/replace, unknown role 422

Prior `ApiEndpointTest` (50) unchanged and still green after the harness extraction.

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (938 tests, 2987 assertions)
```

Baseline 899 → 938: **+39 tests**, none broken.

---

## Verified over real Apache HTTP

```
GET  /api/v1/search?q=renderer      200
GET  /api/v1/media                  200
GET  /api/v1/settings               200
GET  /api/v1/themes                 200  [{"name":"default", …}]
GET  /api/v1/themes/active          200
GET  /api/v1/users                  200
GET  /api/v1/redirects              200
GET  /api/v1/content/3/seo          200
PUT  /api/v1/settings               200  (CSRF token required)
POST /api/v1/redirects              201
POST /api/v1/redirects  (loop)      409
GET  /api/v1/media        (anon)    401
GET  /api/v1/settings     (anon)    401
GET  /api/v1/users        (anon)    401
```

---

## Known limitations

### Endpoint groups still impossible

| Group | Why |
|---|---|
| `comments` | no comments module, no table. Would need a schema + module first. |
| `ads` | no ads module, no table. Agent 14 product-feature territory. |
| `notifications` | no module, no table. Agent 14. |
| `plugins` | no plugin system. Agent 14 owns it. |
| `sites` (super-admin) | there is **no** super-admin concept: no capability exists that spans tenants, and exposing one would violate the rule that Site A must never read Site B data. Inventing `site.manage` here would be scope expansion. |

### Other gaps

1. **Media upload is not exposed.** `Request` has no multipart parsing, so a file
   cannot be read without new HTTP infrastructure. Listing, reading and metadata work.
2. **No pagination totals on media.** The service has no count method, so `meta`
   carries page and per_page only — not `total`/`total_pages`. Adding a count query
   would be one repository method.
3. **Settings writes are not transactional across keys.** A `PUT` with three values
   applies them one at a time; a failure midway leaves a partial update.
4. **`GET /users/{id}` leaks `roles` only for self or `users.read`** — intended, but
   worth stating.
5. **No `ETag`/conditional requests.**
6. **Search has no `total` on the public page parity** — the API does return totals.

---

## CONTRACT_CHANGE_REQUEST

none

---

## Ready to merge

yes
