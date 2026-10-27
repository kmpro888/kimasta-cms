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

## Two defects the gate found

### 1. The archive listing cannot use its own index

```sql
EXPLAIN SELECT id, title FROM contents
WHERE site_id = 1 AND status = 'published'
ORDER BY published_at DESC LIMIT 20;
```

```text
type:  ref
key:   uq_contents_slug        -- the unique (site_id, type, slug) key
rows:  24633
Extra: Using where; Using filesort
```

The schema has a purpose-built index for exactly this:

```sql
KEY idx_contents_archive (site_id, type, status, published_at)
```

It is unusable because the listing query does not filter on `type`. MySQL falls back to the slug
key to narrow by `site_id`, reads roughly half the table, discards rows in `WHERE`, and sorts the
result — a filesort over 24,633 rows to return 20.

That is why a cached-miss archive takes 300 ms while a single article takes 14 ms.

**The fix is small and needs a decision:** either the listing passes the content type it already
knows it wants (so the index covers `status` and orders by `published_at`), or the index gains a
variant without `type`. The first is preferable and touches one query builder; the second costs an
extra index to maintain.

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

**The fix is a query change, not an index:** paginate in SQL — join `content_terms` to `contents`,
order by `published_at`, `LIMIT`/`OFFSET` — so the database returns twenty rows instead of twenty
thousand. This was recorded earlier as a known scaling gap ("taxonomy archive resolves all ids then
paginates"); the gate has now priced it.

---

## What the gate does not answer

1. **Both defects are unfixed.** The gate measures; it does not repair. Each needs its own change
   and its own tests.
2. **One machine, one run.** These are single-request wall times on a Windows development box with
   WAMP. The query counts and plans are the durable part; the milliseconds are indicative.
3. **`EXPLAIN` is manual.** The script's own EXPLAIN step records the statement but not its
   bindings, so it cannot plan a parameterised query as written; the plans above were run directly
   against the scratch database with literal values. Automated plan capture is a known gap.
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

yes — as a tool and a report. The two defects it found are open work.
