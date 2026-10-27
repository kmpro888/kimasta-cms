# Redirects and Site Settings

Two more screens over services that already existed, and the one module change that was blocking
the second.

- **§11 Redirect Manager** — `RedirectService` and the redirect middleware existed; a redirect could
  only be added through the API or SQL.
- **§40 Configuration / site settings** — blocked on `SiteServiceInterface` having no `update()`,
  which is now fixed in the Site module rather than worked around in a controller.

---

## Redirects

```text
/admin/redirects        the list, and the form that adds one
```

Authorization is `settings.update`: a redirect is site configuration, and it sends a public URL
somewhere a visitor did not ask to go. An author writing an article should not be able to change
that.

Validation stays in the service and is **not** re-implemented in the controller:

| Rule | Where |
|---|---|
| only 301/302/307/308 | `RedirectService::ALLOWED_STATUS` |
| the site root cannot be redirected | `store()` |
| a target must be a path or http(s) | `isAcceptableTarget()` |
| `//host` is refused | `isAcceptableTarget()`, because it is an off-site redirect in disguise |
| no self-redirect | `store()` |

Each refusal reaches the form as the service's own message, with a 422, and nothing is stored. The
tests assert both halves: the message, and that the row is absent afterwards.

---

## Site settings

```text
/admin/settings        name, base URL, locale, timezone; the domain read-only
/admin/settings/theme  switch the active theme
```

| Field | Why it is here |
|---|---|
| name | header, footer, notification emails |
| base URL | canonical URLs and absolute links |
| default locale | `<html lang>`, date formatting |
| timezone | every rendered date, including the scheduler |
| theme | every page |

**Saving clears this site's page cache.** That is the one place a whole-site flush is right: every
page carries at least one of these values, so a cache left alone would serve pages that disagree
with the settings that produced them. A test changes the name, fetches the article again, and
asserts both a `MISS` and the new name in the HTML.

**The domain is read-only.** Moving a site must move its domain mapping and regenerate the compiled
map, and the old domain stops resolving at once — a typo would lock the site out of its own
administration. A screen should not offer that behind a text field, so it does not.

---

## The module change

```php
// SiteRepositoryInterface
public function update(int $siteId, array $attributes): void;   // NEW

// SiteServiceInterface
public function update(int $siteId, array $attributes): SiteContextInterface;   // NEW
```

`update()` takes an **allow-list** — `name`, `default_locale`, `timezone`, `base_url` — rather than
whatever the caller sent. `slug` and `primary_domain` are deliberately excluded: either would need to
move mappings and rebuild the compiled map, and hiding that inside a generic update would be a trap.
Updating clears the service's request memo, so a caller that writes and then reads gets the new row.

The alternative was writing `UPDATE sites ...` from the admin controller, which is the boundary
violation the module split exists to prevent.

---

## Files

```text
app/Modules/Admin/Http/Controller/RedirectController.php   NEW
app/Modules/Admin/Resources/views/redirects/index.php      NEW
app/Modules/Admin/Http/Controller/SettingsController.php   NEW
app/Modules/Admin/Resources/views/settings/index.php       NEW
app/Modules/Admin/AdminServiceProvider.php                 6 routes, 2 bindings
app/Modules/Admin/Resources/views/layout.php               Redirects + Settings, capability-filtered
app/Modules/Site/Repository/PdoSiteRepository.php          update() with an allow-list
app/Modules/Site/Repository/SiteRepositoryInterface.php    update()
app/Modules/Site/Service/SiteService.php                   update(), memo cleared
app/Modules/Site/Contracts/SiteServiceInterface.php        update()
tests/Integration/Modules/Admin/AdminRedirectTest.php NEW  10 tests
tests/Integration/Modules/Admin/AdminSettingsTest.php NEW  11 tests
```

---

## Tests

| Redirects | |
|---|---|
| access | an editor is refused (403); configuration is an administrator concern |
| happy path | add a 301, see it listed, and `resolve()` returns the target |
| refusals | protocol-relative target, root source, self-redirect, unsupported status — each a 422 and nothing stored |
| delete | removes the row and the resolution |
| CSRF | an action without a token is refused and changes nothing |
| tenant | another site's redirects are neither listed nor resolvable |

| Settings | |
|---|---|
| access | an editor is refused |
| current values | the form shows the site's name, host and a read-only domain |
| save | name, locale, timezone and base URL persist; the trailing slash is trimmed |
| validation | unknown timezone, malformed locale, non-absolute base URL, empty name — all 422 and nothing changes |
| cache | saving flushes the page cache and the new name is visible to a visitor |
| theme | switching works; an unknown theme leaves the active one alone; `themes.manage` is required |
| CSRF | required |
| navigation | both entries appear only for someone who can use them |

```text
PHPUnit 11.5.57 - PHP 8.3.6
OK (1326 tests, 4164 assertions)
```

---

## Known limitations

1. **No redirect import.** A WordPress migration creates redirects through the migration service; a
   CSV of old paths has no path in.
2. **No hit counting.** Nothing records how often a redirect fires, so unused ones cannot be found.
3. **Deleting a redirect is by source path**, which is the natural key in this schema but means the
   form posts a path rather than an id.
4. **The domain cannot be changed from the screen** — deliberate, and the runbook covers moving one.
5. **No site status control** (`active`/`suspended`) yet, although the repository already supports it.
6. **No per-site settings beyond these four fields**: mail sender, cache TTLs and analytics are still
   configured through `.env` and the API.

---

## CONTRACT_CHANGE_REQUEST

none

`SiteRepositoryInterface` and `SiteServiceInterface` each gained one method. Neither is on the frozen
list; both are additive, and every existing implementation is the only one in the project.

---

## Ready to merge

yes
