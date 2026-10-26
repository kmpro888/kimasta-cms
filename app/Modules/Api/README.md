# API Module — REST `/api/v1`

Exposes application capabilities over one consistent versioned surface.

## Ownership

`app/Modules/Api/**`, `/api/v1` routes.

## Structure

```text
Api/
  ApiServiceProvider.php     res ources, middleware, controllers, routes
  Http/
    ApiController.php        tenant/actor helpers, capability checks, envelopes
    Middleware/ApiContextMiddleware.php
    Controller/AuthController.php
    Controller/ContentController.php
    Controller/TaxonomyController.php
  Resources/                 explicit projections
  Validation/Validator.php
```

## The four rules

1. **Controllers do no business logic.** Validate shape, require capability, call
   one service, project through a resource.
2. **Never serialize an entity automatically.** A resource names every field, so a
   new column cannot leak by existing.
3. **Tenant comes from the host, actor from the session.** Nothing else is accepted.
4. **One error shape.** `{"error":{"code","message","details"}}`, no `status` key.

## Request lifecycle

```text
Host header
  → SiteResolver::resolve()             tenant, never from the client
  → AuthenticationService::currentUserId()  identity, never from the client
  → ActorProvider::actorForUser()       capabilities for THAT tenant
  → controller: validate → capability → service → resource
  → {"data":…,"meta":{}}  or  {"error":…}
```

## Resources

| Resource | Used by | Notes |
|---|---|---|
| `ContentResource` | single content | includes `blocks` |
| `ContentSummaryResource` | listings | **no** blocks — structural, not conventional |
| `RevisionResource` | revisions | blocks yes, compiled HTML no |
| `WorkflowLogResource` | editorial history | |
| `TermResource` | taxonomy | |
| `UserResource` | auth | `password_hash` is not listed |

Compiled `content_html` is never exposed: a client that wants markup asks the public
page, so the API cannot become a source of trusted pre-sanitized HTML.

## Visibility

An anonymous caller sees only published content — enforced **in the query**, not by
filtering results, so the total count cannot leak drafts either. A non-public row is
a 404, not a 403, so the endpoint does not confirm that a draft exists.

## Known gap: CSRF

The API authenticates with the session cookie, so a `POST` from a third-party origin
is CSRF-able. Before exposing this surface beyond a trusted admin origin it needs
either a CSRF token header check or token-based API auth. This is the most important
open security item in the module.
