# Agent 14E — Notifications — Implementation Report

Agent: Agent 14E — Notifications
Module: `CMS\Modules\Notification`
Status: DONE

---

## Summary

Internal notifications and queued email for the five content events, with each
event × channel pair independently switchable.

The two channels have deliberately different delivery profiles: internal is written
synchronously (one cheap insert), email is **queued and drained by cron**. Sending mail
inside a publish request would block the editor on a remote server and lose the message
on timeout; a queue needs no long-running worker, which the production constraints
forbid.

This closes Agent 14: all seven sub-agents are now implemented except 14C's HTML UI.

---

## Files added

```text
app/Modules/Notification/Contracts/NotificationServiceInterface.php
app/Modules/Notification/Contracts/MailTransportInterface.php
app/Modules/Notification/Entity/Notification.php
app/Modules/Notification/Mail/NativeMailTransport.php
app/Modules/Notification/Repository/NotificationRepositoryInterface.php
app/Modules/Notification/Repository/PdoNotificationRepository.php
app/Modules/Notification/Service/NotificationService.php
app/Modules/Notification/NotificationServiceProvider.php
app/Modules/Notification/README.md

app/Modules/Api/Http/Controller/NotificationController.php

database/migrations/20261026001400_create_notification_tables.php

tests/Integration/Modules/Notification/NotificationServiceTest.php
```

## Files modified

```text
app/bootstrap.php                                     provider registered
app/Modules/Api/ApiServiceProvider.php                5 notification routes
app/Modules/Identity/Contracts/RoleServiceInterface.php   usersWithCapability() added
app/Modules/Identity/Repository/RoleRepositoryInterface.php
app/Modules/Identity/Repository/PdoRoleRepository.php     reverse capability lookup
app/Modules/Identity/Service/RoleService.php              delegation
bin/cron.php                                          outbox drain job; now uses the shared bootstrap
tests/Support/ModuleDatabaseTestCase.php              notification tables
```

---

## Database migrations

| Migration | Tables |
|---|---|
| `20261026001400_create_notification_tables.php` | `notifications`, `notification_outbox` |

Verified forward and rollback on both databases.

## Contracts provided

`NotificationServiceInterface`, `MailTransportInterface`.

## Contracts consumed

`ContentServiceInterface`, `UserServiceInterface`, `RoleServiceInterface`,
`SettingsServiceInterface`, `EventDispatcherInterface`, `ClockInterface`.

---

## Recipient resolution

| Event | Recipients |
|---|---|
| `content.submitted` | users holding `workflow.review`, **minus the author** |
| `content.revision_requested` | the author |
| `content.approved` | the author |
| `content.scheduled` | the author |
| `content.published` | the author |

Derived at send time through the role assignment, so a role change takes effect
immediately rather than leaving a stale list behind.

This required one additive Identity method, `usersWithCapability()`, because the existing
`capabilitiesForUser()` answers the opposite question. It is a repository join over
`users → user_roles → role_capabilities → capabilities`, site-scoped, active users only.

Excluding the author from `content.submitted` is not cosmetic: an author submitting their
own piece does not need to be told they submitted it, and on a single-editor site it meant
the notification had **no recipient at all** — which is exactly what happened in the first
HTTP verification and made the empty inbox look like a bug.

---

## Event × channel switches

Stored in site settings under the `notifications` namespace as `<event>.<channel>`.

| Channel | Default | Reason |
|---|---|---|
| internal | **on** | harmless: an inbox row nobody reads costs one insert |
| email | **off** | a fresh install must not start mailing people because someone published a post |

---

## Bounded retries

`attempts`, `last_error` and `available_at` exist so a permanently invalid address ends up
`failed` after three attempts — not retried forever, not silently dropped. Backoff is
`300s × attempt`, stored in `available_at` so cron picks it up when due. A test drives all
three attempts and asserts the final state.

---

## Defect found: cron was running a fraction of the application

Adding the outbox job to `bin/cron.php` failed with
`No container binding registered for NotificationServiceInterface`.

Cause: `bin/cron.php` **duplicated the provider list** instead of using the composition
root. It had drifted to 8 of 18 providers — no Comment, Analytics, Advertising,
Notification, Plugin, Api, Search, Seo, Theme or Cache.

That is a whole class of latent bug: every new module had to be remembered in two places,
and the second place was silently wrong. Fixed by requiring `app/bootstrap.php`, so cron
and HTTP share one wiring. The duplication can no longer drift because it no longer
exists.

This also means cron now runs with the real plugin, cache and theme wiring, which it never
did before.

---

## Defect found: mojibake in notification bodies

The first implementation composed bodies with typographic quotes (“ ”). They reached the
client as `â€œ`, i.e. double-encoded, because the string travelled through MySQL, then JSON,
then email.

Replaced with ASCII quotes. Typographic punctuation in server-generated text that crosses
DB, JSON and mail boundaries adds encoding risk for zero benefit — and this text also feeds
plain-text email, where ASCII is the safe default.

---

## Tests added

`NotificationServiceTest` — 16 tests:

- internal on by default, email off by default
- publishing notifies the author in-app
- submission notifies reviewers, not the author
- email is **queued**, not sent
- `processOutbox()` drains it and marks it sent
- a failing transport is retried three times, then marked `failed`
- switching a channel off stops delivery
- both channels on at once produces two records
- unknown event ignored; cross-site content id produces nothing
- unread count and marking read
- another user's notification cannot be marked read
- tenant isolation
- the preference matrix covers every event and channel
- unknown channel / unknown event rejected

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (1059 tests, 3227 assertions)
```

Baseline 1043 → 1059: **+16 tests**, none broken.

---

## Verified over real Apache HTTP

```
GET  /api/v1/me/notifications        (anonymous)   401
POST /api/v1/me/content/{id}/submit                200
POST /api/v1/content/{id}/transitions review       200
POST /api/v1/content/{id}/transitions approved     200
POST /api/v1/content/{id}/transitions published    200
GET  /api/v1/me/notifications                      200  two notifications:
                                                        content.published
                                                        content.approved
GET  /api/v1/notifications/preferences             200  full matrix
```

And cron:

```
Cron started
Scheduled publishing: 0 item(s) published.
Notification email: 0 sent, 0 failed.
Cron finished.
```

---

## Known limitations

1. **No SMTP transport.** `NativeMailTransport` wraps PHP `mail()`, which is what a shared
   host offers. An operator with a relay must bind a different `MailTransportInterface`.
2. **One email per event per recipient.** No digest, no batching — a busy newsroom will
   send a lot of mail.
3. **Preferences are per site, not per user.** An individual editor cannot opt out.
4. **No templates.** Titles and bodies are composed in the service with simple `match`
   expressions; localising or restyling them means editing code.
5. **No purge.** `notifications` grows until an operator prunes it; there is no retention
   job.
6. **Email failures are recorded but not surfaced** in any admin view — an operator has to
   query `notification_outbox` where `status = 'failed'`.
7. **No delivery for plugin or comment events.** The contract lists the five content events;
   extending it means adding to `EVENTS` and giving each a recipient rule.
8. **A truncated `last_error`** (500 chars) may hide the tail of a long relay message.

---

## CONTRACT_CHANGE_REQUEST

none

`RoleServiceInterface` and `RoleRepositoryInterface` gained `usersWithCapability()` —
additive, Identity owns those tables, and no frozen contract changed.

---

## Ready to merge

yes
