# Redirect Hits and CSV Import

`redirects.hit_count` existed and was never incremented. The obvious fix — an `UPDATE` in the
redirect path — is the one thing this project refuses for public traffic: a write per visitor.

---

## Counting without writing

A hit is **one appended line** in `storage/cache/redirect-hits/{siteId}.log`. `bin/cron.php` folds
the lines into `hit_count` in one statement per path.

The trade is stated, not hidden:

- counts are **eventually consistent** — the admin screen shows "N hit(s) counted and waiting for the
  cron flush", so an operator can tell *no hits yet* from *cron has not run*;
- a crash between the append and the flush loses the unflushed lines;
- `drain()` renames the file before reading it, so an append that arrives mid-flush lands in a fresh
  file instead of being truncated away with the lines already read.

Counting is approximate by design. Not counting at all was the alternative.

---

## CSV import

`POST /admin/redirects/import` takes a file (`source, target, status`), 2 MB maximum.

- a **header row is skipped by content**, not position, so a file without one still works;
- each row is validated by the same `RedirectService::store()` the form uses — no second rulebook;
- the report says how many were stored and how many were refused, with the first five reasons:
  `Imported 2 redirect(s). Skipped 1: line 4: A redirect target must be a path or an http(s) URL.`

An importer that silently drops half a file is worse than no importer.

---

## The screen

A **Hits** column, the pending count under the table, and the import form.

---

## The tests

| | |
|---|---|
| counting | a redirect answers `301` and increments **no row**; the pending count rises |
| flushing | two hits flush to `hit_count = 2` and the file is drained |
| the screen | the Hits column and the pending sentence are rendered |
| import | two valid rows stored, one invalid refused, and the report reaches the next page |
| authorization | import requires `settings.update` |

```text
php vendor/bin/phpunit --filter RedirectHitsAndImportTest
OK (5 tests, 17 assertions)
```

Writing the tests found a second bug: `listForSite()` did not select `hit_count`, so the number was
invisible to every caller — the column existed and no screen could have shown it.

---

## Files

```text
app/Modules/Seo/Support/RedirectHitCounter.php        NEW  append, drain, pending
app/Modules/Seo/Service/RedirectService.php           recordHit(), flushHits(), pendingHits()
app/Modules/Seo/Contracts/RedirectServiceInterface.php
app/Modules/Seo/Repository/{PdoRedirectRepository,RedirectRepositoryInterface}.php  incrementHits(), hit_count in the listing
app/Modules/Seo/SeoServiceProvider.php                counter beside the cache
app/Modules/Theme/Http/PublicPageController.php       one append per redirect
bin/cron.php                                          the flush job
app/Modules/Admin/Http/Controller/RedirectController.php  import()
app/Modules/Admin/Resources/views/redirects/index.php     Hits column, pending count, import form
tests/Integration/Modules/Seo/RedirectHitsAndImportTest.php  NEW  5 tests
```

---

## Known limitations

1. **Eventual consistency** — the totals lag until cron runs; the screen says so rather than showing
   a number that looks exact.
2. **Lost lines on a crash** between append and flush; no journal.
3. **No per-day breakdown.** A single total cannot answer "did the 301 help last week".
4. **The file grows between cron runs**; a site with no cron eventually accumulates one line per
   redirect. The flush drains it, but a host that never runs cron will keep growing.
5. **No CSV export**, and the import has no dry run.
6. **Import is additive**; an existing source is updated, not reported as a conflict.
7. **No import size beyond 2 MB** and no streaming parse for very large files.

---

## Ready to merge

yes
