# Notification Screens and Sitemap Sharding

Two PRD gaps closed, both without needing a product decision.

- **§32 Notification** — the service, the API and the per-event switches existed; no screen did.
- **§10/§12 Sitemap index** — the PRD asks for one; the code stopped at 50,000 URLs and silently
  dropped the rest.

---

## Notification screens

```text
/admin/notifications               my notifications, all or unread
/admin/notifications/preferences   the site's switches, event x channel
```

Two different scopes behind one menu entry, and the difference is the authorization:

| | Whose | Capability |
|---|---|---|
| the list | **mine** — `listForUser($siteId, $userId)` | any signed-in person |
| the switches | **the site's** — one row per event and channel for the whole tenant | `settings.update` |

So a contributor can read and clear their own notifications, and cannot change the site's email
behaviour. An editor gets a 403 on the switches too: `settings.update` belongs to the
administrator role, which is the same rule the API already enforced.

Behaviours worth stating:

- Marking read is scoped to the current user, and another person's notification is a **404**, not a
  403 — confirming it exists would leak their inbox.
- Every box is rendered, including the off ones, and the controller writes every known event and
  channel from the form. An unchecked box is never submitted, so "save only what arrived" would
  make turning something off impossible.
- Email starts off and in-app starts on, which is the service's own default and is stated on the
  screen.
- A new event added to the service appears on the screen without editing the view.

---

## Sitemap sharding

```text
small site        /sitemap.xml            one <urlset>
large site        /sitemap.xml            <sitemapindex>
                  /sitemap-1.xml          <urlset>
                  /sitemap-2.xml          <urlset>   ...
```

The old version collected entries until 50,000 and then stopped. A larger site kept a sitemap that
looked fine and quietly omitted everything past the cap — the failure mode that deindexes content
without an error anywhere.

Now:

- the shard size is the protocol limit (50,000) and is a parameter, so the behaviour is testable
  without inserting fifty thousand rows;
- a hard ceiling of 20 shards (1,000,000 URLs) bounds memory;
- **shrinking removes stale shards**, because an index pointing at a file that is no longer written
  is a broken sitemap;
- `invalidate()` removes the index and every shard;
- a shard that was never written is a **404**, not an empty `<urlset>` — an empty urlset tells a
  crawler those URLs are gone.

---

## Three defects found while building the sharding

### 1. The router silently ignored a parameter inside a segment

`Route::compilePattern()` treated a segment as a parameter only when the **whole** segment was
`{name}` / `{name?}` / `{name:regex}`. Anything else fell through to `preg_quote()`, so

```php
$router->get('/sitemap-{shard}.xml', ...)
```

compiled to the literal text `sitemap-{shard}.xml` and **matched no path at all** — a route that
exists, passes review, and does nothing. The docblock already warned about a similar trap with
slashes; this was the same trap with literal text around the parameter.

Fixed: a segment containing `{...}` now compiles to quoted literals around named captures. Pinned
by the shard route working, and by the unchanged behaviour of every existing route (`/blog/x`,
`/api/v1/health`, the theme catch-all).

### 2. A route naming a controller class only ever calls `handle()`

Registering the shard route as `SitemapController::class` resolved the class as a
`RequestHandlerInterface` and called `handle()` — the *index* action — for every shard URL. That is
why `/sitemap-9.xml` answered **200** with a sitemap instead of 404: the wrong method was serving
it.

Fixed by registering a closure that calls `shard()` explicitly, which is how the API routes already
pick an action.

### 3. Sitemap artifacts were shared between databases

`storage/cache/system/sitemaps/` held **71 files** — the development site's `sitemap-2.xml` beside
dozens from test runs whose site ids no longer exist (526, 1202, 1950, … 9335). The same class of
contamination as the domain map fixed earlier: the test suite writes artifacts from the test
database into a directory the development site also reads.

Scoped per database, and the directory was cleaned:

```text
storage/cache/system/sitemaps/line_cms/sitemap-2.xml
storage/cache/system/sitemaps/line_cms_test/…
```

---

## Files

```text
app/Modules/Admin/Http/Controller/NotificationController.php   NEW
app/Modules/Admin/Resources/views/notifications/index.php      NEW
app/Modules/Admin/Resources/views/notifications/preferences.php NEW
app/Modules/Admin/AdminServiceProvider.php                     5 routes, 1 binding
app/Modules/Admin/Resources/views/layout.php                   Notifications in the nav
public/assets/admin.css                                        notification cards
app/Core/Routing/Route.php                                     parameters inside a segment
app/Modules/Seo/Service/SitemapService.php                      sharding, index, cleanup
app/Modules/Seo/Contracts/SitemapServiceInterface.php           generate($maxUrls), readShard, pathForShard
app/Modules/Seo/Http/SitemapController.php                      shard() action
app/Modules/Seo/SeoServiceProvider.php                          shard route as a closure, per-database directory
tests/Integration/Modules/Admin/AdminNotificationTest.php  NEW  11 tests
tests/Integration/Modules/Seo/SitemapShardingTest.php      NEW   8 tests
```

---

## Verified

```text
/admin/notifications              303 -> login when anonymous
/sitemap.xml                      200 (3 URLs, one <urlset>)
/sitemap-1.xml                    404 on the dev site (no shards at three URLs)
cron                              Sitemap: 3 URL(s) across 1 site(s).
```

A site generated with a shard size of 2 over five URLs produces an index and three urlsets, four
URLs shard-by-shard, and every URL present exactly once across the shards.

```text
PHPUnit 11.5.57 - PHP 8.3.6
OK (1292 tests, 4071 assertions)
```

---

## Known limitations

1. **No screen for redirects, users/roles, plugins or site settings.** Still the four admin gaps
   from the PRD audit.
2. **The sitemap is not paginated by date.** One index with up to 20 shards; per-day or per-month
   indexes are not built.
3. **No `lastmod` on the index entries**, only on the URLs.
4. **Test sitemap artifacts accumulate** under `sitemaps/line_cms_test/`, one set per test because
   each test gets a new site id. Harmless, gitignored, and safe to delete.
5. **The notification list is not paginated** (50 most recent) and there is no unread badge in the
   navigation — it would cost one query on every admin page.

---

## CONTRACT_CHANGE_REQUEST

none

`SitemapServiceInterface` gained an optional parameter and two methods; `Route` gained support for
a case it silently mishandled. No frozen contract changed.

---

## Ready to merge

yes
