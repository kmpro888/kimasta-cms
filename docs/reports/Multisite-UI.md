# Multisite From the UI

Creating a tenant used to be a CLI snippet or a hand-written `INSERT`. It is not an insert: the
domain row, the compiled domain map, the role catalogue and the taxonomy defaults all have to exist,
and a site nobody can sign in to is not usable.

---

## The screen

`/admin/sites` — visible to anyone with `settings.update`, listed in the navigation as **Sites**.

| | |
|---|---|
| list | every site, its domains (primary marked), and its locale |
| create | name, slug, primary domain, locale, timezone — **and its first administrator** |
| domains | add an alias, remove an alias; the primary cannot be removed |

Creating a site does all of this before it redirects:

1. normalises the host, and refuses one already mapped (see the defect below);
2. inserts the site, the `site_domains` row and recompiles `storage/cache/system/domain-map.php`;
3. seeds the role catalogue — without roles the new tenant cannot appoint anybody;
4. seeds the taxonomy defaults;
5. creates the first administrator and assigns it the `administrator` role.

Then it says where to sign in:

```text
Created Second Site. Sign in at http://second.example/admin/login as secondadmin.
```

---

## The switcher: not built, and why

The item asks for "site creation, domain mapping, site switcher". The first two exist. The third
would be a lie: **users are tenant-scoped** (`users.site_id`). An account belongs to exactly one
site, so there is no identity that could switch between two. The isolation this project chose is the
reason — a switcher would require accounts shared across tenants, which is a schema change and a
security decision, not a menu.

What the operator does instead: sign in at each site's own domain. That is what the test asserts,
and it is why the create form insists on a first administrator — otherwise the new tenant would be
unreachable.

---

## The defect this screen found

The first version created the site row and then added the domain. A duplicate domain therefore
**left an orphan site**: a tenant that exists, has no domain, cannot be reached, and cannot be seen
in any screen that lists sites by host.

Fixed in `SiteService::create`: the domain is checked *before* the insert. A test asserts that a
refused duplicate leaves the site count unchanged.

The same review found a duplicate **slug** escaping as a `500`, because it hits the unique index
rather than a validation rule. It now returns `422` with a form error, and the catch is narrowed to
SQLSTATE `23000` so a genuine database failure is not disguised as a validation message.

---

## The tests

| | |
|---|---|
| authorization | `settings.update` required for the screen and for every action |
| creation | site exists, domain mapped, roles seeded, **and the new administrator can sign in at the new host** |
| isolation | an unauthenticated request to the second site's admin is redirected to *its* login; the first site's article never appears |
| refusals | duplicate domain (422, no site created), duplicate slug (422), malformed slug (422), short password (422) |
| domains | alias added → resolves to the same site; alias removed; primary refused |
| navigation | the entry is shown only to someone who can use it |

```text
php vendor/bin/phpunit --filter AdminSiteTest
OK (8 tests, 29 assertions)
```

---

## Files

```text
app/Modules/Admin/Http/Controller/SiteController.php   NEW
app/Modules/Admin/Resources/views/sites/index.php      NEW
app/Modules/Admin/AdminServiceProvider.php             four routes, binding
app/Modules/Admin/Resources/views/layout.php           the "Sites" entry
app/Modules/Site/Contracts/SiteServiceInterface.php    create(), domains() — were only on the concrete class
app/Modules/Site/Service/SiteService.php               duplicate-domain pre-check, domains()
lang/en.php, lang/id.php                               nav.sites
tests/Integration/Modules/Admin/AdminSiteTest.php      NEW  8 tests
```

---

## Known limitations

1. **No site switcher** — see above; accounts are tenant-scoped.
2. **No delete or archive.** `sites.status` exists and the migration checks it, but no screen changes
   it. That is on the PRD-gap list as "site status control".
3. **No theme choice per site**, though `sites.theme` is a column and the theme resolver already
   reads it.
4. **No invitation email.** The operator sets the first password; there is no mail transport in this
   installation.
5. **No DNS or ownership verification** of an added domain — anyone who can reach the screen can
   claim a hostname. That is a deliberate small-core choice, and it is a real one: with many
   operators it would need verification.
6. **The create sequence is not transactional.** A failure after the site insert (for example a
   duplicate username) leaves a site without an administrator; the flash says so, but the operator
   must then create the user by hand.
7. **No per-site storage quotas**; uploads share one filesystem root.

---

## Ready to merge

yes
