# Plugin Update Path

`install()` was already an upsert, so updating a plugin existed only as "install it again and hope".
Two things were missing, and the screen could show neither: an operator never knew a newer version
was on disk, and a click on Update could silently do nothing.

---

## What changed

`PluginManagerInterface` gained:

- **`update(string $name)`** — refuses when the plugin is not installed, refuses when it is already at
  the version on disk, and otherwise runs the plugin's migrations **before** recording the new
  version. Migrations first, for the same reason install does it: a schema that fails must not be
  recorded as an update that happened.
- **`updates()`** — installed plugins whose on-disk version is newer, with both versions.

The migration ledger is per plugin and per file, so an update runs only the files that are new. It
is an update, not a reinstall.

`PluginUpToDateException` (409) exists so "nothing to do" is an error an operator sees, not a success
message that hides a no-op.

---

## The screen

A **Update available: 0.0.1 → 1.0.0** badge, an Update button that appears only when there is
something to do, a confirmation naming both versions, and an audit entry (`plugin.updated`) with the
resulting version.

---

## The tests

Five tests against a **temporary plugin with no migrations**, built with the real manager:

| | |
|---|---|
| the update | a stale install becomes the on-disk version, and the row records it |
| not installed | refused, not silently "installed" |
| already current | refused with both versions, as a 409 |
| `updates()` | empty when current, lists the plugin and both versions when behind |
| the screen | no badge when current, badge and form when behind |

```text
php vendor/bin/phpunit --filter PluginUpdateTest
OK (5 tests, 20 assertions)
```

Two things this round taught about the harness itself:

- installing the **shipped example plugin** inside these tests registers its capabilities and
  disturbs the fixture. The tests use a temporary plugin instead — the update path is what is under
  test, not plugin capability registration.
- `createSiteRow()` inserted `example.com` unconditionally. A test that failed before its cleanup
  left the row behind, and every later `setUp` died on the unique key. It now clears the row first,
  which makes the harness idempotent instead of order-dependent.

---

## Files

```text
app/Modules/Plugin/Contracts/PluginManagerInterface.php   update(), updates()
app/Modules/Plugin/Service/PluginManager.php              the update path
app/Modules/Plugin/Exceptions/PluginUpToDateException.php NEW
app/Modules/Admin/Http/Controller/PluginController.php    update action, version state
app/Modules/Admin/Resources/views/plugins/index.php       badge + button + confirmation
app/Modules/Admin/AdminServiceProvider.php                the route
tests/Integration/Modules/Plugin/PluginUpdateTest.php     NEW  5 tests
tests/Support/ModuleDatabaseTestCase.php                  idempotent site fixture
```

---

## Known limitations

1. **No file replacement.** The update reads what is already in `plugins/`; pushing new files is a
   deployment step, not a button. There is no upload/zip installer, deliberately.
2. **No downgrade.** A plugin whose on-disk version is older than the recorded one is refused by the
   same version comparison; there is no rollback.
3. **No migration rollback on failure.** A failed update leaves the migrations that already ran in
   place; the version is not recorded, so the next attempt resumes from the failed file.
4. **No dependency upgrade ordering.** `requires_plugins` is checked at activation, not at update.
5. **No update for non-installed plugins** — that is what Install is for.
6. **No scheduled/automatic updates.**

---

## Ready to merge

yes
