# CSRF Hardening — Implementation Report

Agent: Controller-integrated (closes the top open security item from Wave 7)
Module: `CMS\Core\Security`, `CMS\Modules\Api\Http\Middleware`
Status: DONE

---

## Summary

Wave 7 closed with an explicit warning: the REST API authenticated with the session
cookie and had **no CSRF protection on any mutation**, so a third-party page could
make a logged-in user create, edit or delete content.

This closes it. The frozen `CsrfTokenManagerInterface` had shipped from Agent 01 with
no implementation and no container binding — which is why nothing was protecting
anything. It now has both.

Also tightened: the three editorial read endpoints that were still anonymous.

---

## Files added

```text
app/Core/Security/CsrfTokenManager.php
app/Modules/Api/Http/Middleware/CsrfMiddleware.php
tests/Unit/Core/CsrfTokenManagerTest.php
```

## Files modified

```text
app/Core/Bootstrap/CoreServiceProvider.php            contract bound (lazy)
app/Modules/Api/ApiServiceProvider.php                middleware split: $read / $write
app/Modules/Api/Http/Controller/AuthController.php    GET /auth/csrf
app/Modules/Api/Http/Controller/ContentController.php history/transitions/revisions gated
tests/Integration/Modules/Api/ApiEndpointTest.php     +8 tests, helper sends a token
```

---

## The contract that was never implemented

```text
CsrfTokenManagerInterface  (frozen, Agent 01)
  ├─ implementations found: 0
  └─ container bindings:    0
```

`grep` across `app/` returned only the interface itself. Every cookie-authenticated
mutation was forgeable, and nothing had failed because no endpoint existed to be
forged against yet.

`CsrfTokenManager` implements it:

| Property | How |
|---|---|
| Unpredictable | `random_bytes(32)`, 64 hex chars |
| Server-side | stored in the session, never derived from user data |
| Timing-safe | `hash_equals`, not `===` |
| Session-bound | a token from one session fails in another |
| Rotatable | `regenerate()` invalidates the previous token |

---

## Enforcement

```text
GET  /api/v1/auth/csrf   →  {"data":{"token":"<64 hex>"},"meta":{}}
```

Every non-safe API request must carry the token in `X-CSRF-Token` (or `_token` in
the body). The middleware is applied from a **single `$write` list** in the provider,
so a newly added mutation route cannot be registered without it — and a test asserts
that for all twelve existing routes.

### Why the token is required on login too

Login CSRF is real: an attacker can force a victim's browser to authenticate into the
attacker's account and then read what the victim writes there. Exempting login would
have left that open, so it is protected like every other mutation.

### Why the check runs before authentication

A forged anonymous `POST` gets the CSRF rejection, not a 401. That way the response
does not tell an attacker whether the endpoint expects a session.

---

## Bug found: 419 became 500 over Apache

The first implementation used `419` (the Laravel convention) with code
`CSRF_TOKEN_MISMATCH`. Over real Apache:

```text
status=500
{"error":{"code":"CSRF_TOKEN_MISMATCH", ...}}
```

419 is an unassigned status code, and Apache translates it to 500 — so a forged
request would have reached the client as a **server error**, which is both wrong and
alarming. Changed to `403` (registered, reliable through shared hosting) while keeping
the specific error code, which is what a client should branch on anyway.

This would not have been visible from the test suite alone: `Response::status()` was
correctly `419` in-process. Only the HTTP verification caught it.

---

## Editorial reads now gated

`GET /content/{id}/transitions`, `/history` and `/revisions` were public. That
revealed editorial state — including that a non-public draft exists — to anonymous
callers. All three now require `content.read`, using the same capability constant as
draft visibility.

---

## Tests added

- unit: `CsrfTokenManagerTest` (10)
  - token shape (64 hex), stability across calls, valid/invalid/empty rejection
  - rejection when no token was ever issued
  - regeneration invalidates the previous token
  - cross-session isolation
  - not prefix-based; not suffix-tolerant
  - recovers from a corrupt session value
- integration: `ApiEndpointTest` (+8, now 50)
  - token obtainable, missing token → 403, wrong token → 403
  - CSRF rejection precedes authentication
  - token accepted from the body `_token`
  - safe methods need no token
  - **all twelve mutation routes** are CSRF protected (guards future endpoints)
  - editorial reads 401/403 anonymously, 200 with the capability

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (899 tests, 2887 assertions)
```

Baseline 881 → 899: **+18 tests**, none broken.

---

## Verified over real Apache HTTP

```text
POST /api/v1/content            (no token)         403  CSRF_TOKEN_MISMATCH
GET  /api/v1/auth/csrf                             200  token, 64 hex
POST /api/v1/auth/login         (with token)       200  user payload
GET  /api/v1/content/3/history  (anonymous)        401
GET  /api/v1/content/3/revisions (anonymous)       401
```

---

## Security impact

| Attack | Before | After |
|---|---|---|
| Cross-origin `POST /content` with victim's cookie | **succeeded** | 403 |
| Login CSRF (force victim into attacker's account) | possible | 403 |
| Token guessing | – | 256-bit random, `hash_equals` |
| Token replay across sessions | – | rejected |
| Draft existence probing via `/transitions` | leaked | 401 |

---

## Known limitations

1. **No `SameSite` cookie enforcement is verified.** CSRF tokens cover the case;
   `SameSite=Lax` on the session cookie would be defence in depth and should be
   confirmed on the session configuration.
2. **No token rotation on privilege change** beyond login. Logout destroys the
   session, which invalidates it; a role change mid-session does not rotate.
3. **No per-request token** (one token per session). Fine for a CMS; a one-time token
   per form would break multi-tab editing.
4. **Content-Security-Policy is not applied to the API.** Not needed for JSON, but
   the admin UI will need it when it exists.
5. **No rate limiting** on login or password reset — the remaining unbounded
   brute-force surface.

---

## CONTRACT_CHANGE_REQUEST

none

`CsrfTokenManagerInterface` was **implemented**, not changed. This report exists
because a frozen contract shipped without an implementation; the interface itself is
untouched.

---

## Ready to merge

yes
