# Version Control and CI

The project had 473 PHP files, 15 migrations and 36 documents with **no version control and no
way to run its own verification**. That was the largest risk in the project: every check built
over this work existed only as long as the session that ran it, and any edit was unrecoverable.

This report covers putting the code under git and making the pipeline runnable.

---

## What was done

```text
git init -b main
  commit 1  ca81a58  Import the platform as a starting point   (584 files, 90637 insertions)
  commit 2  67d2475  Make a fresh deploy migrate completely, and make CI runnable
```

One import commit rather than a fabricated history: the work predates version control, and
inventing a sequence of commits that never happened would misrepresent it. History starts here,
and future work is atomic.

### Tracked, 584 files

`app/` · `themes/` · `plugins/` · `public/` (minus uploads) · `bin/` · `config/` · `database/`
· `docs/` · `tests/` · `phpunit.xml` · `composer.json` · `composer.lock` · `.env.example`

### Not tracked, and why

| Path | Reason |
|---|---|
| `.env` | secrets stay on the machine |
| `vendor/` | installed by composer, not written |
| `storage/` | cache, sessions, logs, locks: regenerable. Directories kept via `.gitkeep` |
| `public/uploads/` | user media, **except `.htaccess`** |
| `.dsh-acl-recovery/` | harness artifacts that had leaked into the project directory |
| `links.txt`, `need-agent.md`, `.env.test-prod` | local scratch. Listed in `.gitignore` rather than deleted: they belong to whoever is working here |

### Line endings

`.gitattributes` normalises to LF in the repository. The working tree mixed CRLF and LF, so
without it a checkout on another machine would show whole-file diffs and hide real changes.

---

## The bug this task found before it shipped

`.gitignore` contained:

```text
/public/uploads/*
!public/uploads/.gitkeep
```

which ignores **everything** in `public/uploads` except the placeholder — including
`public/uploads/.htaccess`, the guard that stops an uploaded file from being executed as PHP.

The consequence would have been silent and serious: a clone of the repository would boot
normally, look correct, pass its own tests, and serve a site where any uploaded file runs as
code. Nothing in the test suite would have caught it, because tests run against the working
copy where the file exists.

Fixed by adding `!/public/uploads/.htaccess`, and verified by cloning the repository and running
`bin/doctor.php`, which reports `uploads guard ... denies script execution` from the clone.

---

## Three deployment defects found by trying it

Writing the pipeline and following the runbook surfaced problems that reading the code would
not have.

### 1. `bin/migrate.php` silently stopped at five migrations

The runner batches in rounds of five to bound execution time on shared hosting; the CLI called
it once. With fifteen migrations, a **fresh install was left two thirds migrated** while the
command printed `Executed 5 migration(s).` — a deploy that reports success and then answers
"table not found".

The CLI now repeats until a round finds nothing to do. The batching stays inside the runner,
where it belongs: an operator should not have to know about it.

Verified on an empty throwaway database:

```text
Executed 15 migration(s).
Pending: 0
```

### 2. CI depended on MySQL client binaries that may not exist

The pipeline waited for MySQL with `mysqladmin` and created the database with `mysql`. Neither
is guaranteed on the runner image. Both steps now use PHP with `pdo_mysql`, which `setup-php`
has just installed — and waiting in PHP also proves the driver works before anything else runs.

### 3. CI proved reversibility with a single rollback

Rollback works in batches, so `php bin/migrate.php rollback` undid one batch and the pipeline
called that proof. It now loops until nothing is left, then re-applies.

### Also corrected

- The runbook advertised `bin/migrate.php rollback 3`. The command takes **no argument**.
- The runbook never mentioned `composer install`, without which a checkout cannot boot, because
  `vendor/` is not tracked.
- `composer.json` requires `php >=8.2`, but CI ran a matrix from `8.1` and the doctor's floor was
  `8.1.0`. Aligned on 8.2 (the composer constraint) everywhere, with a test that pins it:
  `theFloorMatchesTheComposerRequirement`.

---

## Verification

A clone is the only honest test of a `.gitignore`. Done end to end:

```text
git clone  ->  584 files
copy vendor/ and .env  (what an operator does)
php bin/doctor.php     ->  8 ok, 2 warning(s), 0 failure(s)

warnings, both expected in a fresh clone:
  app key     short APP_KEY, because the dev .env was copied
  sitemap     no artifact yet; storage/cache is empty until cron runs
```

And the application serves a page from the clone:

```text
GET /admin/login  ->  200, 1223 bytes
```

The `uploads guard` check passing **from the clone** is the specific proof that the `.gitignore`
bug is fixed.

```text
PHPUnit 11.5.57 - PHP 8.3.6
OK (1196 tests, 3810 assertions)
```

---

## CI: written, not yet executed

`.github/workflows/ci.yml` exists and was corrected, but it has **never run**. There is no
remote, so it cannot be pushed from here. It is written to run, not proven to run; the first
push is the real test.

To make it live:

```bash
git remote add origin git@github.com:<account>/<repo>.git
git push -u origin main
```

Three jobs:

| Job | Runs | Time |
|---|---|---|
| lint | every PHP file parses, PHP 8.2 and 8.3 | seconds |
| unit | `--testsuite Unit`, both versions | ~1 s |
| full | migrate -> rollback loop -> migrate -> doctor -> full suite, MySQL 8.0 | ~7 min |

The full job is only practical because the suite went from 22 minutes to 7; that speedup is what
made CI worth building.

---

## Known limitations

1. **The workflow has never executed.** Expect the usual first-run surprises: `setup-php`
   extension names, the mysql service health check, `.env` shaping.
2. **No `vendor/` in the repository**, so a deploy needs Composer, or `vendor/` uploaded by hand.
   Documented in the runbook.
3. **Single branch, no tags, no releases.** Fine for now; a version tag per deploy would let a
   rollback name a revision instead of describing one.
4. **No pre-commit hook.** Nothing stops a commit that fails lint; CI catches it after the push
   rather than before.
5. **The import commit is enormous** (90,637 insertions). `git log -p` on it is not useful; the
   history becomes meaningful from commit 2 onward.
6. **CI uses MySQL 8.0** while development targets MySQL 8.3 and the runbook also claims MariaDB.
   Engine behaviour that differs would not be caught.

---

## CONTRACT_CHANGE_REQUEST

none

---

## Ready to merge

yes -- and it is committed.
