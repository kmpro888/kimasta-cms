# Final Verification

The finish line was "no known defect, no unfulfilled PRD claim, and every claim proven by an
automated check". This is the audit of that sentence: what is proven, by what, and what is
deliberately not claimed.

---

## The ten items

| # | Item | Report | Test class | Proof |
|---|---|---|---|---|
| 1 | Browser E2E (headless Chrome) | `Browser-E2E.md` | `tests/E2E/BrowserE2ETest.php` | 6 tests render public pages and admin screens through Chrome |
| 2 | Load / concurrency, stampede lock | `Cache-Stampede.md` | `tests/Performance/CacheStampedeTest.php` | 10 concurrent readers against a held lock; every waiter serves the published entry |
| 3 | WordPress migration, realistic export | `WordPress-Realistic-Export.md` | `RealisticExportTest.php`, `WordPressMigrationTest.php` | multisite prefixes, 3-level categories, threaded comments, guest comments, entity meta reporting |
| 4 | Accessibility audit | `Accessibility-Audit.md` | `tests/Accessibility/AccessibilityAuditTest.php` | 17 pages, 14 mechanical checks, proven able to fail |
| 5 | OpenAPI for the API | `OpenAPI-Generated.md` | `tests/Contract/Api/ApiDocumentationTest.php` | generated from the router; drift, shape and auth truth |
| 6 | CSP + security headers | `CSP-And-Security-Headers.md` | `tests/Security/Core/SecurityHeadersTest.php` | strict policy, no inline handlers left, 404 carries headers |
| 7 | i18n | `I18n-Locale.md` | `TranslatorTest.php`, `CatalogCoverageTest.php`, `LocaleRenderingTest.php` | the locale changes rendered pages; catalogs cannot drift |
| 8 | Multisite from the UI | `Multisite-UI.md` | `tests/Integration/Modules/Admin/AdminSiteTest.php` | create a site + its first admin, alias domains, reach it on its own host |
| 9 | Seven ad placements | `Ad-Placements.md` | `PlacementRenderingTest.php` | four printed and asserted, three named unsupported with reasons |
| 10 | The seven PRD gaps | see below | see below | each has its own report and tests |

### Item 10, in full

| Gap | Report | Test class |
|---|---|---|
| audit log | `Audit-Log.md` | `AuditLogTest.php` |
| comment replies + email | `Comment-Replies-And-Email.md` | `CommentReplyTest.php` |
| redirect hits + CSV import | `Redirect-Hits-And-Import.md` | `RedirectHitsAndImportTest.php` |
| plugin update | `Plugin-Update-Path.md` | `PluginUpdateTest.php` |
| revisions diff | `Revisions-Diff.md` | `RevisionDiffAndAtomTest.php` |
| Atom feed | `Atom-Feed.md` | `RevisionDiffAndAtomTest.php` |
| site status | `Site-Status.md` | `SiteStatusTest.php` |

---

## The evidence

```text
php vendor/bin/phpunit
OK (1504 tests, 5344 assertions)          ~11 minutes, MySQL 8.3, PHP 8.3

php bin/doctor.php
9 ok, 1 warning, 0 failures
(warning: cron is not scheduled in this checkout — a host configuration, not a code defect)

CI run #36 (GitHub Actions, kmpro888/kimasta-cms, commit 7c69acc)
Lint 8.2 ✓   Lint 8.3 ✓   Unit 8.2 ✓   Unit 8.3 ✓   Full suite (MySQL) ✓
```

```text
curl -sI http://line.id/atom.xml   → 200 application/atom+xml; charset=utf-8
curl -sI http://line.id/feed       → 200 application/rss+xml; charset=utf-8
php bin/cron.php                   → sitemap 3 URLs, feed 2 items, redirect hits 0, email 0
```

Migrations are idempotent: the CI job runs `bin/migrate.php`, then rolls every migration down and up
again, and only then runs the suite.

---

## What is **not** claimed

The reports state these; repeating them here is the point of a final verification.

1. **Accessibility** is "no mechanical violations", not "accessible": contrast, focus visibility and
   screen-reader phrasing need a person.
2. **The ad module** supports four placements in this theme. Three are named unsupported, with
   reasons, rather than silently absent.
3. **No site switcher.** Accounts are tenant-scoped (`users.site_id`); an operator signs in at each
   site's domain. A switcher would need shared accounts, which is a different isolation model.
4. **The audit log covers six actions**, not everything: content edits, media, taxonomy and API
   mutations are not recorded yet.
5. **Revision diffs** are block-level, not word-level, and align by index.
6. **The API document has no per-endpoint schemas**; controllers parse input by hand.
7. **Redirect hit counts are eventually consistent** and can lose unflushed lines on a crash. That is
   the price of not writing a row per visitor, and it is stated on the screen.
8. **CSP allows `style-src 'unsafe-inline'`** and an operator can opt into inline scripts for an
   analytics snippet; the default is strict.
9. **Plugin update has no file replacement or downgrade**; it runs new migrations for files already
   in `plugins/`.
10. **The WordPress fixture is synthetic** — shaped after a real export, not somebody's database.

---

## Defects this work found and fixed

Every item found at least one real bug; these are the ones worth remembering.

| Found by | Defect |
|---|---|
| the stampede test | the lock serialised writes but every waiter still re-rendered |
| the realistic export | category hierarchy and comment threads were read and then discarded; guest comments were dropped |
| the accessibility audit | nothing — the pages already passed, which is reported as such |
| the OpenAPI generator | the document depended on which plugin was active |
| the CSP work | five inline `onsubmit` handlers, and a footer partial calling `$renderer` it never receives |
| the ad placements | seven placements configured, zero printed; listings had no ads at all |
| the audit log | a logger bound nowhere; deleted users logged with a null subject |
| comment replies | `comment.approved` carried no `site_id`, so its listener returned before notifying anyone |
| redirect hits | `listForSite()` never selected `hit_count` |
| plugin update | the test harness inserted `example.com` unconditionally, breaking every later `setUp` |
| revisions / Atom | a route whose handler is a class name only ever calls `handle()` — `/atom.xml` served RSS |
| site status | `SiteResolver` memoised mutable site state, so a deactivated site kept answering 200 |

---

## Reproduce

```text
php bin/migrate.php
php bin/doctor.php
php bin/openapi.php            # regenerates docs/api/openapi.json
php bin/cron.php               # publishes scheduled content, flushes hits, rebuilds feeds
php vendor/bin/phpunit
php vendor/bin/phpunit --testsuite Accessibility
php vendor/bin/phpunit --testsuite E2E
```

---

## Ready to merge

yes
