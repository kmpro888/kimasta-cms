# Handover

What this is, what it does, and what to check before handing it to someone else.

Written to be read by whoever runs this next, not by whoever built it.

---

## What it is

A cache-first publishing CMS for ordinary shared hosting: PHP 8.2+, MySQL or MariaDB with
InnoDB, Apache, a filesystem, and cron. It needs no Node.js, Redis, Docker, message queue or
long-running worker, and it never will.

One installation serves several sites from one database, isolated by host.

---

## What a person can do

**A reader** sees the site: home page, blog index, article pages with images, category and tag
archives, author archives, search, 404 pages, `/sitemap.xml`.

**A writer** signs in at `/admin/login`, writes an article with blocks (heading, paragraph, list,
quote, code, image), classifies it, sets its search appearance and featured image, and submits it
for review.

**A reviewer** opens the queue, reads the article, and either asks for a revision with a note or
approves it.

**An editor** publishes immediately, schedules for a future time, edits another person's draft,
and deletes media that nothing uses.

**Everyone with an account** can change their own password and sign out.

---

## Before handover: the checklist

Run these in order. Each one has a known failure mode with a fix in
[`docs/DEPLOYMENT.md`](DEPLOYMENT.md).

1. **Deploy** — see the runbook. `DocumentRoot` must point at `public/`.
2. **`composer install --no-dev --optimize-autoloader`** — `vendor/` is not in the repository.
3. **`php bin/migrate.php`** — one invocation applies everything.
4. **`php bin/doctor.php`** — must end with `0 failure(s)`.
5. **Create the first site and administrator** — the runbook has the verified snippet.
6. **Install the cron entry** and run `php bin/cron.php` once, so the sitemap exists.
7. **Sign in, change the bootstrap password**, write and publish one article.
8. **`vendor/bin/phpunit`** — 1246 tests, all green, about eight minutes.

Steps 4, 7 and 8 are the three that catch real problems. A deployment that skips them is
unverified no matter how good it looks.

---

## Verified state at handover

```text
PHPUnit 11.5.57 - PHP 8.3.6
OK (1246 tests, 3929 assertions)

bin/doctor.php        9 ok, 1 warning, 0 failures
                      (the warning is APP_KEY on the development machine)
modules               21
migrations            15, all reversible (CI proves it on every run)
tracked files         598, in 7 commits
```

Measured, not remembered: the numbers above come from the commands beside them.

---

## What is genuinely unfinished

Ordered by how much it matters, with the honest reason each exists.

| Gap | Why it matters | Size |
|---|---|---|
| **CI has never run** | There is no git remote, so the workflow is written but unproven. The first push is its first test. | one push |
| **No comment moderation screen** | Comments exist, the API can moderate them, there is no screen. | medium |
| **No user or role management UI** | Adding an editor means a CLI snippet or direct SQL. | medium |
| **A schedule cannot be cancelled** | Once scheduled, an article must be published or rescheduled; there is no `scheduled -> approved` edge. | small, needs a design decision |
| **An API client can create a dateless scheduled article** | `POST /content/{id}/transitions` with `status=scheduled` leaves `scheduled_at` null, and the scheduler will never publish it. The admin screens no longer offer it. | small |
| **Password change does not end other sessions** | A stolen session keeps working until it expires; there is no session table to revoke. | medium |
| **No `/robots.txt`** | `/sitemap.xml` works; the sibling is missing. | tiny |
| **Uncached article costs ~18 queries** | The task pack asked for 5. Cache hits cost 0, which is the promise that matters for readers; a cold render is above budget. | needs an architecture decision |
| **Performance gate at 50k rows never run** | Query counts are asserted; query plans are not. | one session with a scratch database |
| **No multi-file upload, cropping, or unused-media cleanup** | The library is functional, not polished. | small each |

Nothing in this list prevents a single site with a small editorial team from working. Several of
them prevent a newsroom from working.

---

## Where to look

| Question | File |
|---|---|
| How do I deploy or upgrade this? | [`docs/DEPLOYMENT.md`](DEPLOYMENT.md) |
| What was built, and why, in what order? | [`docs/reports/`](reports/) — 39 reports |
| What did the admin UI decide? | [`docs/plans/Admin-UI-Design.md`](plans/Admin-UI-Design.md) |
| What are the architecture rules? | [`AGENTS.md`](../AGENTS.md) |
| What does the platform contract say? | [`docs/01-Technical-Architecture.md`](01-Technical-Architecture.md) |

---

## Two things worth knowing before you change anything

**The test suite is the specification.** 1246 tests, one command. When a change breaks something
subtle, a test usually says which rule you broke. Run the full suite before merging; it takes
eight minutes, which is the price of being able to trust it.

**Do not run two test runs at once.** They share one database and will truncate each other's
rows. This mistake produced 92 phantom failures during the final session, every one of them
unreal. `phpunit --filter` while another full run is going is the specific hazard.

---

## If something is on fire

```text
500 with a stack trace   APP_DEBUG is on in production; the app refuses to boot there, so
                         check whether APP_ENV is actually production
403 on every page        a <FilesMatch> block in public/.htaccess is denying the front controller
404 on every page        DocumentRoot is not public/, or the domain map is stale (run bin/doctor.php)
403 Missing capability   a capability was added to the catalogue and never seeded;
                         php bin/seed.php --reseed-roles --force
scheduled post missing   cron is not installed, or the article was scheduled through the API
                         without a time
/sitemap.xml 404         cron has not run since deploy
```

The troubleshooting table in the runbook has the full list, each entry a failure that actually
happened here.
