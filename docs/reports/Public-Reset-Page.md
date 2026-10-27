# The Public Reset Page

The last report ended with an honest edge: an administrator could issue a password reset link, and
the link had nowhere to land. `/reset-password?token=…` was a themed 404.

This closes the chain.

---

## What exists now

```text
GET  /reset-password?token=…    the form, or a page that explains why not
POST /reset-password            sets the password and becomes usable
```

Registered as explicit GET and POST routes **before the theme catch-all**, so this path never
reaches the page renderer.

Decisions worth stating:

- **A missing or unusable token is not a 404.** The visitor arrived from a link somebody sent them;
  "not found" reads as "you typed it wrong". The answer is a page saying the link no longer works
  and pointing at the sign-in page. The form is shown for a well-formed token and the token is
  validated on submit, because the service exposes no validate-only call and inventing one to
  decorate a GET is not worth a contract.
- **`no-store` and `no-referrer`** on every response: the page carries a live credential while the
  form is open, and a referrer header would leak it to wherever the visitor clicked next.
- **The token is the secret and it is single use.** There is no CSRF token here for the same reason
  the comment form has none: an anonymous visitor has no session to bind one to, and the credential
  itself is the proof.
- **A rejected attempt does not burn the token.** Mismatched passwords or a short one return the
  form; only a successful redemption consumes it. A visitor who mistypes once should not have to ask
  for a new link.
- **Rendered as a self-contained page**, not a theme template, for the same reason the comment
  confirmation is: it must work under any theme, and it must never be cached.

---

## Verified on the running site

```text
GET  /reset-password                    422  text/html   "That link is incomplete"
GET  /reset-password?token=bogus        200  the form
POST /reset-password  token=bogus       422  "That link no longer works"
```

And in tests, the chain end to end: issue a token, load the form, submit, then check that the stored
hash verifies against the new password **and no longer** against the old one.

---

## Files

```text
app/Modules/Theme/Http/PasswordResetController.php   NEW
app/Modules/Theme/ThemeServiceProvider.php           2 routes, 1 binding
tests/Integration/Modules/Theme/PasswordResetPageTest.php  NEW  7 tests
```

---

## Tests

| | |
|---|---|
| form | served for a valid token, with `no-store` |
| missing token | 422 with an explanation, not a 404 |
| success | the password changes, the new hash verifies, the old one does not |
| single use | the same token twice is refused the second time |
| mismatch | refused **without** consuming the token; a retry with the same token succeeds |
| policy | a short password is refused with the minimum length |
| invention | a made-up token is refused |

```text
PHPUnit 11.5.57 - PHP 8.3.6
OK (1388 tests, 4366 assertions)
```

---

## Known limitations

1. **The page is not themed.** It is a self-contained document with inline styles, by design; a
   theme cannot restyle it.
2. **No token validation on GET.** A well-formed but expired token shows the form, and the visitor
   learns on submit.
3. **No "request a new link" button.** The page tells them to ask an administrator, which is the
   only path, since the public site has no forgot-password form.
4. **No password strength meter or breach check**, only the length policy.
5. **The reset does not end existing sessions.** A session that was already open elsewhere survives
   the password change — the same gap recorded for the admin password change.
6. **No rate limit on this endpoint.** The token is high-entropy and single use, but nothing stops a
   script from trying many.

---

## CONTRACT_CHANGE_REQUEST

none

---

## Ready to merge

yes
