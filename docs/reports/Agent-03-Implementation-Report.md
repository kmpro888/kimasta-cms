# Agent 03 — Identity, Auth & RBAC — Implementation Report

Agent: Agent 03 — Identity, Auth & RBAC
Module: `CMS\Modules\Identity`
Status: DONE

---

## Summary

Implemented authentication, session handling, users, roles and capability
authorisation over six tables. Authorisation is data-driven: capabilities are
resolved from `role_capabilities` through `user_roles` in one joined query, and
`Actor::can()` is a set lookup on the result. Role names never appear in a
decision.

The module was started by a sub-agent and completed by the controller after the
sub-agent terminated mid-task; see `Wave-2-Tracking.md` for the handover.

---

## Files added

```text
app/Modules/Identity/IdentityServiceProvider.php
app/Modules/Identity/README.md

app/Modules/Identity/Contracts/AuthenticationServiceInterface.php
app/Modules/Identity/Contracts/AuthorizationServiceInterface.php
app/Modules/Identity/Contracts/PasswordResetServiceInterface.php
app/Modules/Identity/Contracts/UserServiceInterface.php
app/Modules/Identity/Contracts/RoleServiceInterface.php
app/Modules/Identity/Contracts/ActorProviderInterface.php

app/Modules/Identity/Entity/User.php
app/Modules/Identity/Entity/Role.php
app/Modules/Identity/Entity/Capability.php

app/Modules/Identity/Exceptions/IdentityException.php
app/Modules/Identity/Exceptions/InvalidCredentialsException.php
app/Modules/Identity/Exceptions/AccountDisabledException.php
app/Modules/Identity/Exceptions/DuplicateUserException.php
app/Modules/Identity/Exceptions/InvalidResetTokenException.php
app/Modules/Identity/Exceptions/ExpiredResetTokenException.php

app/Modules/Identity/Repository/UserRepositoryInterface.php
app/Modules/Identity/Repository/PdoUserRepository.php
app/Modules/Identity/Repository/RoleRepositoryInterface.php
app/Modules/Identity/Repository/PdoRoleRepository.php
app/Modules/Identity/Repository/CapabilityRepositoryInterface.php
app/Modules/Identity/Repository/PdoCapabilityRepository.php
app/Modules/Identity/Repository/PasswordResetRepositoryInterface.php
app/Modules/Identity/Repository/PdoPasswordResetRepository.php

app/Modules/Identity/Service/AuthenticationService.php
app/Modules/Identity/Service/AuthorizationService.php
app/Modules/Identity/Service/PasswordResetService.php
app/Modules/Identity/Service/UserService.php
app/Modules/Identity/Service/RoleService.php

app/Modules/Identity/Auth/Actor.php
app/Modules/Identity/Auth/ActorProvider.php
app/Modules/Identity/Session/NativeSession.php
app/Modules/Identity/Support/CapabilityCatalogue.php

database/migrations/20261025000200_create_identity_tables.php
```

## Files modified

```text
app/bootstrap.php                        provider registered (composition root)
docs/reports/Wave-2-Tracking.md          controller log
```

The `app/Core/Bootstrap/Application.php` fix is recorded in the Agent 02 report;
it is a Core bug that blocked both modules equally.

---

## Contracts consumed

```text
CMS\Shared\Contracts\ActorInterface        implemented by Auth\Actor
CMS\Shared\Contracts\SessionInterface      implemented by Session\NativeSession
CMS\Shared\Contracts\ClockInterface        all time-sensitive logic
CMS\Shared\Contracts\SiteContextInterface  consumed indirectly via explicit site ids
CMS\Core\Database\Connection               via ConnectionFactory
```

## Contracts provided

```text
CMS\Shared\Contracts\ActorInterface         Auth\Actor
CMS\Shared\Contracts\SessionInterface       Session\NativeSession
Contracts\AuthenticationServiceInterface    Service\AuthenticationService
Contracts\AuthorizationServiceInterface     Service\AuthorizationService
Contracts\PasswordResetServiceInterface     Service\PasswordResetService
Contracts\UserServiceInterface              Service\UserService
Contracts\RoleServiceInterface              Service\RoleService
Contracts\ActorProviderInterface            Auth\ActorProvider
```

Neither frozen contract was modified. Contract tests assert method-for-method
conformance, including `Actor::can()` arity and the optional `resource` argument.

---

## Database migrations

| Migration | Tables |
|---|---|
| `20261025000200_create_identity_tables.php` | `users`, `roles`, `capabilities`, `role_capabilities`, `user_roles`, `password_resets` |

InnoDB, utf8mb4, explicit FKs with `ON DELETE CASCADE`, `down()` present and
verified in reverse dependency order.

Multisite constraints encoded in the schema:

- `users` — unique `(site_id, username)` and `(site_id, email)`, so the same
  email may exist on two sites but not twice on one.
- `roles` — unique `(site_id, slug)`; roles are per tenant.
- `capabilities` — global (an ability, not a tenant); unique `capability_key`.
- `password_resets` — stores `token_hash` only, indexed by `(site_id, user_id)`
  and `expires_at`.

---

## Events emitted

None. Authentication and reset flows are synchronous and their side effects are
contained in the module's own tables. Emitting `identity.user.logged_in` would
be reasonable for analytics later but was not requested.

## Events consumed

None.

---

## API changes

None. Login/logout/reset endpoints are thin controllers owned by Agent 13. This
module registers no routes.

---

## Cache impact

None of its own. Identity is deliberately not on the cached public path: the
anonymous public page must not need a session, a user row or a capability
lookup.

`ActorProvider` resolves a capability set once per request and `Actor` holds it
as a set, so an admin page performing dozens of `can()` checks costs one query,
not dozens.

---

## Multisite impact

- Every repository method carries `site_id` in its `WHERE`, including `UPDATE`
  and `DELETE` — a forgotten predicate would be a silent cross-site write, so
  the scoping is unconditional.
- A user id from another site resolves to `null`, which the services surface
  identically to a nonexistent row.
- A role cannot be assigned across sites.
- A reset token issued on one site is invalid on another.
- Same email on two sites is explicitly supported and tested.

---

## Security impact

| Property | How it is enforced |
|---|---|
| No plaintext passwords | `password_hash()` / `password_verify()` only; a test greps the column for a canary string |
| No login oracle | Unknown identifier and wrong password raise the same exception with the same message; the unknown path still runs a dummy `password_verify` to equalise timing |
| No account-state leak | `disabled` is only reported after the password verifies |
| Session fixation | `session_regenerate_id(true)` runs **before** the identity is written; a test asserts the exact call order |
| Session cookie hardening | HttpOnly, SameSite=Lax, Secure on HTTPS, `use_only_cookies`, `use_strict_mode` |
| Logout invalidation | identity forgotten, session destroyed, cookie expired |
| Reset token at rest | SHA-256 hash only, never the plaintext |
| Reset token replay | atomic `UPDATE … WHERE used_at IS NULL`; sibling tokens invalidated on consume |
| Reset enumeration | `issue()` returns `null` for unknown/disabled accounts instead of throwing |
| Password rehash | `password_needs_rehash()` upgrades stale hashes on successful login |
| Anonymous escalation | `Actor::can()` returns false whenever the user id is null, with no override path |

---

## Tests added

- unit: `tests/Unit/Modules/Identity/ActorTest.php` (11),
  `CapabilityCatalogueTest.php` (10)
- integration: `tests/Integration/Modules/Identity/AuthenticationTest.php` (15),
  `AuthorizationTest.php` (14), `PasswordResetTest.php` (15),
  `UserSiteIsolationTest.php` (12)
- contract: `tests/Contract/Modules/Identity/ActorContractTest.php` (4)
- security: `tests/Security/Modules/Identity/AuthSecurityTest.php` (13)
- performance: not applicable to this module; no hot-path caching.

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (304 tests, 795 assertions)
```

Full suite, all testsuites.

---

## Query/performance notes

- Capability resolution is one joined query, not one per role — no N+1.
- Login is one indexed lookup (`username` OR `email`, both covered by unique
  keys) plus one `UPDATE` for `last_login_at`.
- `INSERT IGNORE` for role/capability grants keeps `seedDefaults()` idempotent
  without a read-then-write cycle.
- Explicit column lists everywhere; no `SELECT *`.
- Prepared statements only. Note for later modules: native prepares forbid
  reusing a named placeholder inside one statement — this bit both modules and
  is now documented in the code.

---

## Known limitations

1. No rate limiting or account lockout on the login endpoint. The
   `RATE_LIMITED` code exists in the frozen envelope vocabulary but throttling
   belongs to the API layer (Agent 13), where the request context is available.
2. No "remember me" persistent login. The session lifetime is the only control.
3. Password strength policy is not enforced here beyond non-empty; a policy hook
   belongs to the settings layer once Agent 02's settings service is wired into
   admin forms.
4. `NativeSession` uses PHP's default file session handler; a database or
   file-cache handler would be needed for multi-server deployments, which is out
   of scope for shared hosting.
5. `AuthorizationService::can()` answers the site-wide capability question only.
   Resource-scoped rules (e.g. "may edit *this* article") are the responsibility
   of the module owning the resource and receive `$resource` for that purpose.

---

## CONTRACT_CHANGE_REQUEST

none

`ActorInterface` and `SessionInterface` were implemented as frozen. No competing
interface was created.

---

## Ready to merge

yes
