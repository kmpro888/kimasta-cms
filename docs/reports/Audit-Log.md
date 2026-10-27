# Audit Log

The PRD asks for an audit trail. **Nothing existed**: content transitions had their own workflow log,
but no record of who created a user, changed a setting, activated a plugin, or created a site.

---

## What it is

```text
audit_log
  id, site_id, actor_id, actor_name, action, subject_type, subject_id,
  context_json, ip, created_at
```

Three deliberate properties:

1. **Append-only.** The repository interface has `append()`, `listForSite()` and `countForSite()` —
   no `update`, no `delete`, no `clear`. A test asserts those methods do not exist, because a log
   that can be rewritten is not evidence.
2. **Tenant-scoped.** Every row carries `site_id`, and every read takes one. A test proves one site's
   trail is invisible to another.
3. **The actor is snapshotted.** `actor_name` is resolved when the action happens, so deleting a user
   does not turn their history into `user #7`. There is no foreign key to `users` for the same
   reason: a cascade would erase exactly what an audit exists to keep.

Recording is **best effort**. A failure to write the log never fails the action it describes — the
setting was changed, and an error page about the log would be the log's fault.

---

## What is recorded today

| Action | Where |
|---|---|
| `user.created`, `user.updated`, `user.deleted` | the people screens |
| `settings.updated` | the settings screen |
| `plugin.installed`, `plugin.uninstalled`, `plugin.activated`, `plugin.deactivated` | the plugins screen |
| `site.created` | the sites screen, recorded against the **new** site |

Deliberately **not** recorded yet, and named rather than implied:

- content publish/unpublish/delete (the workflow log records transitions, not actor-facing edits);
- redirects, ads, taxonomy, media, themes;
- API mutations;
- authentication events (sign-in, failed sign-in, password reset).

Those are the next slice, not a claim of coverage. The mechanism is one call at the mutation site, so
adding them is mechanical rather than architectural.

---

## The screen

`/admin/audit`, listed as **Audit log**, gated on `settings.update` (the administrator capability).

It shows when (UTC), who, what action, what subject, and the context JSON. It filters by action name
and by a trailing-dot group (`user.` finds every user action), paginates 50 to a page, and offers no
edit or delete control — because there is no such action to reach.

```text
php vendor/bin/phpunit --filter AuditLogTest
OK (8 tests, 31 assertions)
```

---

## What building it found

- **`LoggerInterface` is not bound in the container.** The first version took a logger to complain to
  when a write failed; resolving it threw, and every audit write became a 500. The logger dependency
  is gone and the catch is silent, with the reason written down.
- **`$userId` in the delete action did not exist.** The record call referenced a variable from a
  different scope, so deleted users were logged with a null subject — the exact row an audit is most
  needed for. It uses `$user->id` now, and the test asserts the subject id.

---

## Files

```text
database/migrations/20261027000100_create_audit_log_table.php   NEW
app/Modules/Audit/AuditServiceProvider.php                     NEW
app/Modules/Audit/Contracts/AuditLoggerInterface.php            NEW
app/Modules/Audit/Entity/AuditEntry.php                         NEW
app/Modules/Audit/Repository/AuditLogRepositoryInterface.php    NEW
app/Modules/Audit/Repository/PdoAuditLogRepository.php          NEW
app/Modules/Audit/Service/AuditLogger.php                       NEW
app/Modules/Admin/Http/Controller/AuditController.php           NEW
app/Modules/Admin/Resources/views/audit/index.php               NEW
app/Modules/Admin/AdminServiceProvider.php                      binding + route
app/Modules/Admin/Http/Controller/{User,Settings,Plugin,Site}Controller.php  four call sites
app/Modules/Admin/Resources/views/layout.php                    the nav entry
lang/en.php, lang/id.php                                        nav.audit
tests/Integration/Modules/Audit/AuditLogTest.php                NEW  8 tests
```

---

## Known limitations

1. **Coverage is partial** — see the list above; content, redirects, ads, media, taxonomy and auth
   events are not recorded.
2. **No client IP.** The column exists and is written as `null`: `Request` has no client-address
   accessor, and inventing one from `$_SERVER` inside the logger would be the wrong layer.
3. **No retention policy.** Rows accumulate. A shared host running for years would want a prune job;
   there is none, and no index on `created_at` alone beyond the composite.
4. **The screen requires `settings.update`.** A dedicated `audit.read` capability would let an
   auditor read without the power to change settings; it is a follow-up, not a pretence.
5. **No tamper-evidence** (hash chaining). Rows can be edited by anyone with database access — the
   absence of a delete *path* is a code-level guarantee, not a cryptographic one.
6. **UI actions only.** Writes made directly through API controllers are not recorded.

---

## Ready to merge

yes
