# Identity Module — Auth, Users & RBAC

Owns authentication, session handling, users, roles and capabilities.

## Ownership

Tables: `users`, `roles`, `capabilities`, `role_capabilities`, `user_roles`,
`password_resets`.

## Contracts provided

| Contract | Implementation |
|---|---|
| `CMS\Shared\Contracts\ActorInterface` (frozen) | `Auth\Actor` |
| `CMS\Shared\Contracts\SessionInterface` (frozen) | `Session\NativeSession` |
| `Contracts\AuthenticationServiceInterface` | `Service\AuthenticationService` |
| `Contracts\AuthorizationServiceInterface` | `Service\AuthorizationService` |
| `Contracts\PasswordResetServiceInterface` | `Service\PasswordResetService` |
| `Contracts\UserServiceInterface` | `Service\UserService` |
| `Contracts\RoleServiceInterface` | `Service\RoleService` |
| `Contracts\ActorProviderInterface` | `Auth\ActorProvider` |

## Authorisation is data-driven

Role names never appear in an authorisation decision. A user's capability set
is resolved with one joined query over `user_roles → roles →
role_capabilities → capabilities`, and `Actor::can()` is a set lookup on that
result. Adding a role or re-pointing a capability is a data change.

The default matrix lives in `Support\CapabilityCatalogue` and is applied by
`RoleService::seedDefaults()`, which is idempotent.

## Security properties (all covered by tests)

- `password_hash()` / `password_verify()` only; the plaintext never reaches the
  database or the logs.
- Login failure is indistinguishable whether the identifier or the password was
  wrong; the unknown-identifier path still runs a `password_verify` against a
  dummy hash to avoid a timing oracle.
- Account state (`disabled`) is only revealed after the password is verified.
- The session id is regenerated **before** the identity is written.
- `logout()` forgets the identity and destroys the session.
- Reset tokens are stored only as SHA-256 hashes, expire (`60` minutes), and are
  single-use via an atomic `UPDATE … WHERE used_at IS NULL`.
- Consuming one token invalidates any sibling tokens for that user.
- A user id from another site resolves to an anonymous actor, never to a
  foreign capability set.

## Session cookie flags

`HttpOnly` always, `SameSite=Lax`, and `Secure` when the request is HTTPS
(`security.cookie_secure` AND the request scheme). `session.use_only_cookies`
and `session.use_strict_mode` are forced on.

## Not implemented here

Login/reset/logout HTTP endpoints belong to Agent 13 (API). This module exposes
services and the actor; it registers no routes.
