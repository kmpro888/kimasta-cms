# Two Services With a Way In Again: Revisions and Reset

Both had a complete, tested service and no route. That pattern has driven most of this project's
gaps, and these were the last two of them.

---

## 1. Revisions can be restored

`RevisionService` wrote a snapshot on every save and could `restore()` a numbered version — with
nothing anywhere to call it. A history nobody can roll back is an audit log at best.

```text
/admin/content/{id}/revisions              the history
POST .../revisions/restore                 put one back
```

- **Requires `content.update`**: an editor who may change the article may change it back.
- **Restore snapshots the live version first**, so a restore is itself undoable. Without that,
  "restore" is a delete with a friendly name. A test asserts the replaced title survives as a new
  revision.
- **A revision belonging to another article is a 404**, not a silent write into the wrong row: the
  form posts both ids and they must agree.
- The link sits on the article editor, because a feature reachable only by typing a URL is not
  reachable.

---

## 2. An administrator can issue a password reset

`PasswordResetService::issue()` returns a one-time token and `consume()` redeems it. Only the API
could reach it. Meanwhile the public site has **no "forgot password" page**, so a user who forgot
their password had no path at all, and an administrator could only tell them to make a new account.

```text
POST /admin/users/{id}/reset-password      requires users.update
```

**The link is shown to the administrator, not emailed.** Two reasons:

1. The token is the thing; email is one transport for it. A screen that silently depends on working
   mail is a screen that fails on a fresh install — and this project deliberately does not require
   mail to operate.
2. It is honest about the trust model: the administrator already holds `users.update`, which can
   change the account anyway.

The flash carries `/reset-password?token=…` once. A test extracts it from the page and redeems it
through the service, then checks the new password hashes and verifies — the whole chain, not just
that a button exists.

---

## Not done, and why: a sites/domains screen

The third item was a screen for creating sites and mapping domains. It cannot be built honestly
yet:

```text
SiteServiceInterface:  findById, findBySlug, all, update, addDomain, removeDomain, regenerateDomainMap
SiteContextInterface:  no domain list
```

There is **no way to read a site's domains** and **no way to create a site** through the service. A
screen would have to query `site_domains` and `sites` directly from a controller — the boundary
violation the module split exists to prevent. The order is: `SiteService::create()` and a domain
accessor first, then the screen.

Multisite therefore remains data-layer only: it works, and it cannot be operated from the
interface. That is stated here rather than papered over with a half-screen.

---

## Files

```text
app/Modules/Admin/Http/Controller/RevisionController.php            NEW
app/Modules/Admin/Http/Controller/PasswordResetAdminController.php  NEW
app/Modules/Admin/Resources/views/content/revisions.php             NEW
app/Modules/Admin/Resources/views/content/form.php                  the Revisions link
app/Modules/Admin/Resources/views/users/form.php                    the reset-link button
app/Modules/Admin/AdminServiceProvider.php                          3 routes, 2 bindings
tests/Integration/Modules/Admin/AdminRevisionAndResetTest.php  NEW  7 tests
```

---

## Tests

| | |
|---|---|
| history | listed for an administrator |
| access | a contributor is refused (403) |
| restore | the old title returns, and the replaced title survives as a new revision |
| mismatch | a revision from another article is a 404 |
| reset | the link is in the flash, the token is redeemable, the new password verifies |
| access | an unknown user is a 404; a contributor cannot issue a reset |

```text
PHPUnit 11.5.57 - PHP 8.3.6
OK (1381 tests, 4341 assertions)
```

---

## Known limitations

1. **No diff view.** The screen lists revisions; it does not show what changed between two.
2. **No prune.** Revisions accumulate forever; nothing trims old ones.
3. **A reset link is issued, not delivered.** No email, so it must be passed on by hand.
4. **Issuing twice invalidates nothing documented.** The service's existing behaviour applies.
5. **The public reset page does not exist**, so the link the administrator is given has nowhere to
   land: `/reset-password?token=…` would render a 404 from the theme. The token is valid and the
   service redeems it — a **route** consuming it is still missing.
6. **No sites/domains screen** — see above.

Point 5 is the honest edge of this change: the mechanism is now reachable from the admin side, and
the other end of the link is not a page yet.

---

## CONTRACT_CHANGE_REQUEST

none

---

## Ready to merge

yes — with point 5 of the limitations stated plainly.
