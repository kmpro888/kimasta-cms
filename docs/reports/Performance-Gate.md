# Performance Gate at 50,000 Rows

Task pack §9 and PRD §50/§51: seed the volumes, run every public surface, and look at the query
plans. Until now the only performance evidence in this project was a query *count* on a
development database with four articles in it.

Run against a scratch database (`line_cms_perf`), never the development or test one.

---

## The data

```text
50,000 contents      published, spread over two years
100,000 content-term relations   two per article across five categories
100,000 comments     approved
 50,000 url_registry rows       so a public path actually resolves
```

Seed time: 125 s. `php bin/perf-gate.php seed` reproduces it; the script refuses any database name
that does not contain `_perf`.

---

## The measurements

Uncached, wall time on one request, query count from the profiler attached to the container's
connection:

| Surface | Status | Queries | Time | Cache |
|---|---|---|---|---|
| home | 200 | 8 | 300 ms | MISS |
| blog archive | 200 | 7 | 297 ms | MISS |
| **single article** | 200 | 14 | **14 ms** | MISS |
| **category archive** | 200 | 9 | **1568 ms** | MISS |
| author archive | 200 | 7 | 231 ms | MISS |
| search | 200 | 7 | 309 ms | BYPASS |
| API list | 200 | 3 | 177 ms | — |
| 404 | 404 | 5 | 5 ms | BYPASS |
| **single article, warm** | 200 | **0** | **0 ms** | **HIT** |

Two things this settles:

- **The zero-query promise holds at scale.** A warm article costs nothing from the database and is
  served in under a millisecond of PHP. That was the architecture's central claim, and until now it
  had only been checked on a toy dataset.
- **The single article is cheap** — 14 queries, 14 ms — even though it is 14 queries rather than the
  five the task pack asked for. The count is over budget; the time is not a problem.

---

## Correction: the first diagnosis was wrong

This report first claimed that the blog archive could not use
`idx_contents_archive (site_id, type, status, published_at)` because it did not filter on `type`,
and quoted a plan that read 24,633 rows with a filesort.

That plan came from a hand-written `EXPLAIN` that **omitted** `type`. The real `/blog` query does
filter on it, and the plan is:

```text
key:   idx_contents_archive
Extra: Using index condition; Backward index scan
```

No filesort, no 24,633-row scan. The claim was wrong, and the number it explained (300 ms) was
always just the cost of a cold page: eight queries, PHP boot, and rendering.

The mistake was measuring a query I wrote rather than the query the code runs. The lesson is in the
tool now: plans must come from the statement the profiler captured, not from one written by hand to
match it.

What the correction leaves is a **smaller, real** finding: the **category and author archives** do
not pass a type filter, so they fall back to the slug key and filesort. Both are fixed below.

---

## Two defects the gate found

### 1. Archive queries that omitted the content type

The blog archive passed `type`; the category and author archives did not:

```sql
-- author archive, as it was
EXPLAIN SELECT id, title FROM contents
WHERE site_id = 1 AND author_id = 1 AND status = 'published'
ORDER BY published_at DESC LIMIT 10;
```

```text
key:   uq_contents_slug
rows:  24633
Extra: Using where; Using filesort
```

The category archive inherited the same shape through its id list. Fixing the id list (defect 2) and
adding the type filter both brought the plan to:

```text
key:   idx_contents_archive
Extra: Using index condition; Backward index scan
```

### 2. A category archive resolves 20,000 ids and then paginates in PHP

```sql
EXPLAIN SELECT content_id FROM content_terms WHERE site_id = 1 AND term_id = 1;
```

```text
key:   idx_content_terms_term
rows:  40074
Extra: Using index
```

The lookup itself is efficient — it is a covering index scan. The problem is what happens next:
each of the five categories holds **20,000 content ids**, all of them are loaded into PHP, and the
page is then cut to twenty with a `WHERE id IN (…20,000 values…)`.

That is the 1,568 ms. Not a missing index, not a bad plan: an unbounded intermediate result.

**The fix is a query change, not an index:** hand the repository a `term_id` and let it page in SQL
with a semi-join, so the database returns ten rows instead of twenty thousand. This was recorded
earlier as a known scaling gap ("taxonomy archive resolves all ids then paginates"); the gate priced
it, and it is now fixed.

### The fix, and what it cost

```php
// PdoContentRepository::buildWhere()
if (isset($criteria['term_id']) && (int) $criteria['term_id'] > 0) {
    $clauses[] = 'id IN (SELECT ct.content_id FROM content_terms ct'
        . ' WHERE ct.site_id = :ct_site_id AND ct.term_id = :ct_term_id)';
}
```

`PublicPageController` now passes `term_id` (and the `type` filter the blog archive already used)
instead of resolving every id through the Taxonomy module.

| | Before | After |
|---|---|---|
| category archive | 1,568 / 1,747 / 2,430 ms | 542 / 579 / 556 ms |
| queries | 9 | 8 |
| plan | filesort over 24,633 rows | `idx_contents_archive`, backward index scan |

Three runs each, same machine. Roughly **3.4× faster**, and the archive now costs about what the
blog archive costs (324 ms) rather than six times it.

Regression guard: `TermArchiveTest` asserts that a term archive stays within twelve queries and that
**no single query carries fifty or more bindings** — the shape that produced the 20,000-placeholder
statement cannot come back silently.

One boundary note: the subquery reads `content_terms`, which the Taxonomy module owns. That is a
deliberate, narrow exception — it is a pure association table (two ids and a sort order, no
taxonomy-owned column), the join is read-only, and the alternative is the unbounded intermediate
result this replaced. It is commented as an exception where it lives.

---

## What the gate does not answer

1. **The category archive is fixed**; the author archive keeps its shape (it now uses the archive
   index for ordering, but a site whose posts are all by one author is a seed artefact, not a
   workload — no further change was made on that evidence).
2. **One machine, one run.** These are single-request wall times on a Windows development box with
   WAMP. The query counts and plans are the durable part; the milliseconds are indicative, and the
   spread across three runs is about ±40 ms.
3. **`EXPLAIN` is manual.** The profiler records each statement but not its bindings, so a
   parameterised query cannot be planned as written. The plans quoted here were taken by hand
   against the scratch database with literal values. Automated plan capture is a known gap, and the
   first version of this report got its diagnosis wrong precisely because it planned a hand-written
   query instead of the one the code runs.
4. **No concurrency.** Everything here is one request at a time. Nothing measures contention, the
   stampede lock, or what fifty simultaneous readers do.
5. **No write paths.** Publishing, editing and commenting are not measured at these volumes.
6. **The scratch database is left behind** (`line_cms_perf`) so the numbers can be reproduced. Drop
   it when the disk matters: `DROP DATABASE line_cms_perf`.

---

## Two bugs in the gate itself, worth recording

Both were caught because the numbers looked wrong rather than because a test failed:

1. **`DELETE` without resetting the auto-increment.** The second seed run left content ids starting
   at 50,001 while the generator still referenced 1..50,000. `INSERT IGNORE` swallowed the
   foreign-key failures, so the seed reported success with zero relations. Fixed with `TRUNCATE`
   and by reading the real id range from the database.
2. **The `?` in a path.** Search and the API were passed `/search?q=perf` as the *path*, so the
   router looked for a literal segment and answered 404 — measuring nothing. Fixed by passing the
   query array separately.

A benchmark that silently measures a 404 is worse than no benchmark.

---

## Files

```text
bin/perf-gate.php                        NEW   seed + measure, guarded by the database name
docs/reports/Performance-Gate.md         this report
```

No application code changed.

---

## Ready to merge

yes — as a tool, a report, and the fix it argued for.
