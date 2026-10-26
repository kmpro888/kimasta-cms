# Scheduling and Password Change

Two of the last functional holes: an approved article could be published but not scheduled, and
there was no way for a person to change their own password. Along the way this also closed a
password-policy gap that neither screen was about but both would have inherited.

---

## Scheduling

The editor now carries a **Schedule** panel for anyone holding `content.publish`:

```text
POST /admin/content/{id}/schedule    publish_at = 2026-11-01T09:30
```

- The time is parsed in the **site's** timezone. The browser sends `2026-11-01T09:30` with no
  zone; reading that as UTC would publish at the wrong hour for every site that is not on UTC,
  and nobody would notice until an article appeared early or late.
- A time in the past is refused with a message rather than silently treated as "publish now".
- An already scheduled article can be rescheduled; the panel shows the current time and the
  button reads *Reschedule*.
- The capability check and the "may this be scheduled at all" rule stay in the workflow. The
  controller only parses and explains.

### The defect the tests found

`DateTimeImmutable::createFromFormat()` accepts impossible dates by **rolling them over**:
`2099-13-45T99:99` became a real date in the next year, and the article was scheduled on a day
nobody typed. Only `getLastErrors()` reveals it. The parser now rejects any value that raises a
warning or an error.

This was found by a test, not by review, which is the argument for testing the boring parse
paths and not just the happy one.

### A trap I did not fix, and why

The workflow lists `approved -> scheduled` as a legal transition, so the editor *also* offered a
"Scheduled" button in the workflow panel. That button calls `transition()`, which sets the status
without a time — leaving an article the scheduler can never publish, because it only picks rows
whose `scheduled_at` has arrived.

I first made `transition()` refuse `scheduled` outright. That broke 31 existing tests: the
editorial suite deliberately exercises `transition() -> scheduled`, so the platform's own design
treats it as a valid path and my invariant contradicted the state machine rather than fixing it.

Reverted. Instead:

- the two admin screens **no longer offer** `scheduled` as a button — the Schedule panel owns it,
  because it also records the time;
- the remaining hole (an API client calling `POST /content/{id}/transitions` with
  `status=scheduled`) is documented below rather than patched by contradicting the machine.

Changing the state machine or the scheduler's query is a design decision, not a hardening fix,
and it should be made deliberately.

---

## Password change

```text
GET  /admin/password    form: current, new, repeat
POST /admin/password
```

- The current password is required even though the session is authenticated: a session left open
  on a shared machine must not be enough to lock the owner out.
- Re-authenticating also regenerates the session id, which is the right thing after a credential
  change.
- Rate limited, 5 attempts per 5 minutes, on its own bucket.
- Failures are form errors with a status the browser still renders: 422 for a wrong current
  password or a mismatch, 429 when the limiter trips.

---

## The policy neither screen was about

Every path accepted any **non-empty** password, so a one-character password was valid. That is
invisible until someone sets one, and then it is a vulnerability with no warning.

`PasswordPolicy` now enforces a floor of eight characters, applied **in the services** — user
creation, password change and password reset — so the API, the admin form and the reset flow
cannot disagree about it. The reset flow still answers with a generic invalid-token error, so it
cannot be used to probe.

Deliberately no composition rule: forced symbols produce `Password1!`.

`tests/Unit/Modules/Identity/PasswordPolicyTest.php` pins the boundary, the message and that the
length is counted in characters rather than bytes.

---

## Files

```text
app/Modules/Identity/Support/PasswordPolicy.php            NEW
app/Modules/Identity/Service/UserService.php               create + changePassword enforce it
app/Modules/Identity/Service/PasswordResetService.php      consume enforces it
app/Modules/Admin/Http/Controller/AuthController.php       showPassword, changePassword
app/Modules/Admin/Http/Controller/ContentController.php    schedule(), parsePublishAt(), filters
app/Modules/Admin/Resources/views/password.php             NEW
app/Modules/Admin/Resources/views/content/form.php         Schedule panel
app/Modules/Admin/AdminServiceProvider.php                 3 routes, 1 binding
tests/Integration/Modules/Admin/AdminPasswordTest.php      NEW, 10 tests
tests/Integration/Modules/Admin/AdminScheduleTest.php      NEW, 8 tests
tests/Unit/Modules/Identity/PasswordPolicyTest.php         NEW, 7 tests
```

---

## Tests

| Area | Covers |
|---|---|
| password screen | requires sign-in, renders in the shell with the minimum stated |
| password change | wrong current refused and nothing written, mismatch refused, too short refused, empty refused, success signs in with the new password and not the old, rate limited, CSRF required |
| policy | the floor, the boundary, empty, the message, characters vs bytes |
| scheduling | editor can schedule, past refused, malformed refused, contributor refused, non-approved refused, rescheduling replaces the time, panel appears only for those who can use it, current time pre-filled |

```text
PHPUnit 11.5.57 - PHP 8.3.6
OK (1246 tests, 3929 assertions)
```

Baseline before this work: 1221 tests. +25, none broken.

---

## Verified by hand, over Apache

```text
GET  /admin/password                   200, form with minlength=8
POST /admin/password (wrong current)   422, password unchanged
POST /admin/password (valid)           303 -> /admin, new password signs in
GET  /admin/content/9                  Schedule panel with datetime-local
POST /admin/content/9/schedule         303, status=scheduled, scheduled_at=2099-12-01 09:30:00
POST .../schedule (2099-13-45T99:99)   303, scheduled_at UNCHANGED (rejected)
```

Test rows were removed afterwards.

---

## Known limitations

1. **A schedule cannot be cleared.** There is no `scheduled -> approved` edge, so once scheduled
   an article must be published or rescheduled. Cancelling a schedule needs a new transition in
   the state machine.
2. **`POST /content/{id}/transitions` with `status=scheduled` still creates a dateless scheduled
   article**, which the scheduler will never publish. The admin screens no longer offer it; the
   API still allows it. Fixing it properly means either requiring a time on that transition or
   teaching the scheduler to treat a dateless scheduled row as "publish at the next run".
3. **Changing a password does not end other sessions.** A stolen session keeps working until it
   expires; there is no session table to revoke.
4. **No password history or reuse check.**
5. **No "must change on first login" flag**, so the bootstrap administrator created by the
   runbook keeps whatever password the operator typed until they change it here.
6. **The password floor is 8 characters** with no strength estimate; a longer dictionary word is
   accepted, a shorter complex one is not.

---

## CONTRACT_CHANGE_REQUEST

none

`PasswordPolicy` is a new class in Identity; the three service methods gained a validation call,
which narrows what they accept. No frozen contract changed.

---

## Ready to merge

yes
