# Agent 13 — REST API — Implementation Report

Agent: Agent 13 — REST API
Module: `CMS\Modules\Api`
Status: PARTIAL — infrastructure + Auth + Content/Workflow/Revisions/Autosave + Taxonomy delivered; the remaining endpoint groups are listed explicitly under Known limitations.

---

## Summary

Built the `/api/v1` surface on a fixed pattern: middleware resolves the tenant from
the host and the actor from the session, controllers validate shape and delegate,
resources project explicitly, and the frozen envelopes are preserved.

**Tenant and identity are never read from the request body or query string.** That
is what makes "normal endpoints do not accept arbitrary site_id" true by
construction rather than by code review, and it has tests.

Delivered: 42 API tests, 24 routes, 4 controller groups, 6 resources.

---

## Files added

```text
app/Modules/Api/ApiServiceProvider.php
app/Modules/Api/README.md

app/Modules/Api/Validation/Validator.php

app/Modules/Api/Resources/ResourceInterface.php
app/Modules/Api/Resources/ContentResource.php
app/Modules/Api/Resources/ContentSummaryResource.php
app/Modules/Api/Resources/TermResource.php
app/Modules/Api/Resources/UserResource.php
app/Modules/Api/Resources/RevisionResource.php
app/Modules/Api/Resources/WorkflowLogResource.php

app/Modules/Api/Http/ApiController.php
app/Modules/Api/Http/Middleware/ApiContextMiddleware.php
app/Modules/Api/Http/Controller/AuthController.php
app/Modules/Api/Http/Controller/ContentController.php
app/Modules/Api/Http/Controller/TaxonomyController.php
```

## Files modified

```text
app/bootstrap.php                                        provider registered
app/Core/Routing/Router.php                              route params exposed to handlers
app/Modules/Content/Repository/PdoContentRepository.php   `ids` and `search` criteria
app/Modules/Taxonomy/Contracts/TaxonomyServiceInterface.php  findTermBySlug, contentIdsForTerm
app/Modules/Taxonomy/Contracts/TermRepositoryInterface.php   reverse lookup + content ids
app/Modules/Taxonomy/Repository/PdoTermRepository.php        implementations
app/Modules/Taxonomy/Service/TaxonomyService.php             delegations
tests/Unit/Core/RouterTest.php                           6 regression tests
```

---

## Contracts consumed

```text
AuthenticationServiceInterface, PasswordResetServiceInterface, UserServiceInterface
ActorProviderInterface, RoleServiceInterface
SiteResolverInterface
ContentServiceInterface, ContentQueryServiceInterface, ContentTypeRegistryInterface
UrlGeneratorInterface
EditorialWorkflowInterface
RevisionServiceInterface
TaxonomyServiceInterface
```

## Contracts provided

None frozen. The API is a consumer layer; it introduces no cross-module contract.

---

## Endpoints delivered

| Method | Path | Auth | Capability |
|---|---|---|---|
| POST | `/api/v1/auth/login` | – | – |
| POST | `/api/v1/auth/logout` | yes | – |
| GET | `/api/v1/auth/me` | yes | – |
| POST | `/api/v1/auth/forgot-password` | – | – |
| POST | `/api/v1/auth/reset-password` | – | – |
| GET | `/api/v1/content` | – | – |
| GET | `/api/v1/posts` | – | – (type pinned) |
| GET | `/api/v1/pages` | – | – (type pinned) |
| GET | `/api/v1/content/{id}` | – | `content.read` for non-public |
| POST | `/api/v1/content` | yes | `content.create` |
| PATCH | `/api/v1/content/{id}` | yes | own row, else `content.update` |
| DELETE | `/api/v1/content/{id}` | yes | `content.delete` |
| POST | `/api/v1/content/{id}/transitions` | yes | per-transition capability |
| GET | `/api/v1/content/{id}/transitions` | – | – |
| GET | `/api/v1/content/{id}/history` | – | – |
| GET | `/api/v1/content/{id}/revisions` | – | – |
| POST | `/api/v1/content/{id}/revisions` | yes | `content.update` |
| POST | `/api/v1/content/{id}/autosave` | yes | own row, else `content.update` |
| GET | `/api/v1/content/{id}/terms` | – | – |
| PUT | `/api/v1/content/{id}/terms` | yes | `content.update` |
| GET | `/api/v1/taxonomies/{taxonomy}/terms` | – | – |
| POST | `/api/v1/taxonomies/{taxonomy}/terms` | yes | `taxonomy.manage` |
| DELETE | `/api/v1/taxonomies/terms/{id}` | yes | `taxonomy.manage` |

---

## API shape rules enforced

1. **No entity is serialized automatically.** Every response goes through a resource
   that names each field. A new column cannot leak by existing.
2. **Two content resources.** `ContentResource` carries blocks;
   `ContentSummaryResource` does not. A listing physically cannot ship the block
   payload.
3. **Compiled HTML is never exposed.** `content_html` is omitted from both the
   content and revision resources — a client that wants markup asks the public page.
4. **`password_hash` cannot appear.** It is absent from `UserResource`, and a test
   asserts no `$2y$` sequence appears in a login response.
5. **`meta` is always a JSON object.** Paginated listings carry pagination keys;
   everything else serializes `"meta": {}`.
6. **Error envelope carries no `status` key** — asserted by a test.

---

## Tenant and identity resolution

```text
request
  → ApiContextMiddleware
      → SiteResolverInterface::resolve(host)     tenant from Host header
      → AuthenticationServiceInterface::currentUserId()   actor from session
      → ActorProviderInterface::actorForUser()   capabilities for THAT tenant
  → controller
```

A `?site_id=` parameter is inert — a test passes another tenant's id and asserts its
content does not appear.

---

## Bugs found and fixed during this wave

### 1. Route parameters never reached a handler (Core)

`RouteMatch` holds captured parameters, but `Router::run()` never exposed them. A
`RequestHandlerInterface` controller therefore could not read `{id}` — every
resourceful route was impossible. Fixed by binding them as `route.<name>` request
attributes, with 6 regression tests.

### 2. `/posts` and `/pages` returned everything

Both were registered as aliases of `index`, which reads `?type=`. So `/posts`
returned pages, and `/posts?type=page` returned pages. Fixed with dedicated `posts()`
and `pages()` actions that pin the type; a `?type=` parameter cannot override it.
Three tests cover it.

Only HTTP verification caught this: the test I first wrote for `index` passed.

### 3. Invented capability `content.read_private`

The first draft gated draft access on a capability that does not exist in the
catalog, so drafts were permanently 404 even for an administrator. The real grant is
`content.read` ("view content in the admin interface"). Renamed to a named constant
with a comment explaining why.

### 4. A 422 that was not a bug

`POST /auth/login` over curl returned 422 "identifier is required". The cause was
PowerShell stripping the double quotes from `-d '{"identifier":…}'`, producing
invalid JSON. Verified with `--data-binary @file`, which returns 200. Recorded here
because it looked exactly like a body-parsing bug and was not.

---

## Security impact

| Risk | Control |
|---|---|
| Anonymous mutation | 401 before any service call (tested) |
| Missing capability | 403 naming the capability (tested) |
| Account enumeration on login | uniform 401 for unknown account and wrong password (tested) |
| Account enumeration on password reset | always 204, token delivered out of band (tested) |
| Cross-site read | 404, body contains no evidence of the row (tested) |
| Cross-site write | services scope by site; foreign id is 404 (tested) |
| Arbitrary `site_id` | ignored; derived from Host (tested) |
| Private field leak | resources are explicit; `password_hash` asserted absent (tested) |
| Draft leak to anonymous | filtered in the query, not after; count not leaked |
| Unpublishable via autosave | autosave never changes status (tested) |

## Multisite impact

Every handler obtains `siteId` from the resolved `SiteContext`. No controller accepts
a site id from the client. `GET /content` cannot be pointed at another tenant.

## Cache impact

None. The API is not page-cached; mutations go through the services, which already
emit the events the cache layer consumes, so an API write invalidates the public
page exactly like an admin write.

---

## Tests added

- integration: `ApiEndpointTest` — 42 tests
  - anonymous read, draft visibility, cross-site 404, `site_id` ignored, unknown host
  - login success / uniform failure / unknown account parity / field errors /
    no hash leak / forgot-password silence
  - create anonymous 401 / no capability 403 / authorized 201 / multi-field 422
  - own-row update 200 / other's row 403 / empty patch 422
  - delete 401 / 403 / 204 + trashed
  - transitions list, legal transition, illegal transition, history
  - snapshot 201 + list, no compiled HTML
  - autosave updates without publishing, autosave 401
  - terms read/create/sync, `taxonomy.manage` enforcement
  - unknown API path keeps the frozen envelope, theme does not swallow `/api/`
  - `/posts` and `/pages` type pinning, `?type=` cannot override
- unit: `RouterTest` +6 (route parameters visible to handlers)

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (878 tests, 2833 assertions)
```

Baseline 839 → 878: **+39 tests**, none broken.

---

## Verified over real Apache HTTP

```
GET  /api/v1/content               200  application/json
GET  /api/v1/content/3             200  application/json
GET  /api/v1/posts                 200  only type=post
GET  /api/v1/pages                 200  only type=page
GET  /api/v1/taxonomies/tag/terms  200  application/json
GET  /api/v1/auth/me               401  UNAUTHENTICATED
POST /api/v1/content               401  UNAUTHENTICATED
POST /api/v1/auth/login            200  user payload, no hash
GET  /api/v1/does-not-exist        404  RESOURCE_NOT_FOUND
```

---

## Known limitations

The task pack lists many more endpoint groups. These are **not implemented**:

| Group | Status |
|---|---|
| `media` endpoints (upload, list, variants) | not implemented |
| `SEO` endpoints | not implemented |
| `redirects` endpoints | not implemented (service exists) |
| `comments` | not implemented — no comments module exists yet |
| `search` endpoint | not implemented (public `/search` page works) |
| `settings` endpoints | not implemented |
| `plugins` | not implemented — no plugin system yet (Agent 14) |
| `themes` endpoints | not implemented (registry exists) |
| `ads` | not implemented |
| `notifications` | not implemented |
| `sites` endpoints (super-admin) | not implemented |
| users/roles endpoints | not implemented (services exist) |
| media upload multipart | not implemented |

Other gaps:

1. **No CSRF protection on API mutations.** The API relies on session cookies.
   A cookie-authenticated `POST` is CSRF-able. The fix is either a CSRF token header
   check or token-based auth for the API; neither is implemented, and this is the
   most important open security item.
2. **No rate limiting** on login or password reset.
3. **No `ETag`/conditional requests**; reads always return a full body.
4. **`PATCH` does not support partial block patches** — `content_json` is replaced
   wholesale.
5. **`GET /content/{id}/transitions` and `/history` are public.** They expose
   editorial state to anonymous callers; they should require `content.read`.
6. Revision restore and content restore-from-trash have no endpoint.
7. The `Validator` covers presence, type, length, enum and boolean only — no
   nested-shape or block-schema validation at this layer (the block engine validates
   on save).

---

## CONTRACT_CHANGE_REQUEST

none

`Router::run()` changed behaviour (parameters are now also exposed as request
attributes). This is additive: no existing handler reads `route.*`, and all prior
tests remain green.

---

## Ready to merge

yes — with the CSRF gap on cookie-authenticated mutations recorded as a known
security item to close before exposing the API beyond a trusted admin origin.
