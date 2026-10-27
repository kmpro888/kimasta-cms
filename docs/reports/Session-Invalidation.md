# A Password Change Now Ends the Other Sessions

This was the only entry on the gap list that was a security defect rather than a missing feature or
an unverified claim: changing a password locked the front door and left every other key in the lock.

A session opened in another browser — or on a laptop that was stolen — stayed authenticated forever,
which is exactly the situation a password change exists to end.

---

## The mechanism

No migration, no new column. The session stores a fingerprint of the credential it signed in with:

```php
// on login
$this->session->put(self::SESSION_FINGERPRINT, self::fingerprint($user->passwordHash));

// on every authenticated request
if (!hash_equals(self::fingerprint($user->passwordHash), $stored)) {
    $this->logout();   // forgets the identity and destroys the session
    return null;
}
```

`fingerprint()` is `substr(hash('sha256', $passwordHash), 0, 32)` — a hash of the stored hash, so the
session never holds anything that could be replayed as a credential, and the comparison is exact.

Because it is derived from `password_hash`, it changes on **every** path that sets a password:

- the profile screen (`changePassword`),
- an administrator's reset link (`PasswordResetService::consume`),
- any future path that calls `password_hash` on that user.

Nothing has to remember to call an invalidation method — which is why this is a fingerprint and not
a "bump this counter" call that some future code path would forget.

---

## Two consequences worth stating

**One extra read per authenticated request.** `currentUserId()` now loads the user to compare the
fingerprint. Admin pages are not the cached public path; this is one indexed lookup behind an
already-authenticated request, and it buys the guarantee above. It is not free, and it is stated
rather than hidden.

**A session created before this deploy is adopted once.** If a stored session has no fingerprint,
the current one is written and the request continues. After the deploy no new session can reach that
branch — a real login always writes the fingerprint — so this is a one-release grace for sessions
that predate the change, not an ongoing hole.

---

## Two tests had to change, and both were wrong before

1. `theSessionIdRotatesOnLogin` asserted the exact sequence of session writes. It now includes the
   fingerprint. The test's intent — rotation happens before the identity is written — is unchanged.
2. `MultisiteIsolationTest` signed a site-B administrator in **against site A** and then asserted a
   404 for B's media. That was a test convenience the application never allowed: `login($identifier,
   $password, $siteId)` takes a site. The session now verifies that its user belongs to the site it
   authenticated against, so the shortcut stopped working — and the test now logs in the way the
   application does, with an optional site parameter on the helper.

The second one is worth naming: the assertion was passing for the wrong reason before.

---

## Files

```text
app/Modules/Identity/Service/AuthenticationService.php   fingerprint on login, verified per request
tests/Integration/Modules/Identity/SessionInvalidationTest.php  NEW  4 tests
tests/Security/Modules/Identity/AuthSecurityTest.php     rotation assertion updated
tests/Support/AppTestCase.php                           loginAs(): site parameter, fingerprint cleared
tests/Integration/EndToEnd/MultisiteIsolationTest.php   logs in against site B
```

---

## Tests

| | |
|---|---|
| password change | the session that used the old password is no longer authenticated |
| sign in again | the new password works |
| reset link | redeeming it ends the sessions that predate it |
| someone else's change | this session survives |
| rotation | the session id still rotates before the identity is written |

```text
PHPUnit 11.5.57 - PHP 8.3.6
OK (1392 tests, 4373 assertions)
```

---

## Known limitations

1. **One extra query per authenticated request** — accepted, and priced.
2. **Pre-deploy sessions are adopted once**, not invalidated.
3. **No "signed-in devices" list**, so a person cannot end one session deliberately.
4. **No idle timeout beyond the session's own expiry**, and no absolute lifetime cap on a session.
5. **A password change does not reveal that it happened** — no email notification to the account.

---

## CONTRACT_CHANGE_REQUEST

none

---

## Ready to merge

yes
