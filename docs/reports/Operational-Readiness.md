# Operational Readiness — CI, Runbook, Production Configuration

Controller-integrated. Turns a platform that was verified once, in one session, into one that
can be verified by anyone on every push.

---

## What was added

| File | Purpose |
|---|---|
| `.github/workflows/ci.yml` | lint × 3 PHP versions, unit suite × 3, full suite on MySQL 8 |
| `docs/DEPLOYMENT.md` | deploy, upgrade, rollback, and a troubleshooting table from real failures |
| `.env.example` | complete and honest; every key it lists is actually read |
| `bin/doctor.php` | preflight that fails the build on unsafe configuration |
| `app/Core/Support/Doctor.php` | the checks, as pure functions so they can be tested |
| `tests/Unit/Core/DoctorTest.php` | 18 tests pinning every verdict |
| `config/migration.php`, `config/mail.php` | so the env keys for them do something |
| `bin/seed.php` | `--reseed-roles`, and a real composition root |

---

## The reason CI became possible this week

The suite took 22 minutes. Nobody runs a 22-minute suite on every push, so CI would have been
decoration. After the test-harness fix it takes 6. A pipeline that runs in six minutes is one
people actually keep green.

That is the whole argument for doing this now rather than later: before the speedup, every
verification in this project existed only as long as the session that ran it.

---

## `bin/doctor.php`

Ten checks, each one a failure that actually happened while building this platform:

| Check | Failure it prevents |
|---|---|
| PHP version | a host too old to parse the code |
| required extensions (case-insensitive) | `pdo` reported missing because `get_loaded_extensions()` returns `PDO` |
| optional image extensions | silent loss of EXIF and format support |
| `APP_DEBUG` under `APP_ENV=production` | stack traces, SQL and paths shown to visitors |
| `APP_KEY` | signing with an empty secret |
| storage engine | MyISAM accepting migrations then ignoring foreign keys and transactions |
| pending migrations | "table not found" from a working deploy |
| writable paths | a cache miss turning into a 500 |
| uploads guard | an uploaded file becoming remote code execution |
| sitemap artifact | `/sitemap.xml` answering 404 after deploy |

Exit code 1 on any failure, 0 otherwise. Warnings do not fail: they describe degradation, not
breakage.

Verified both ways:

```text
local:       9 ok, 1 warning(s), 0 failure(s)   exit 0
production + APP_DEBUG=true:
             3 ok, 0 warning(s), 1 failure(s)   exit 1
             [FAIL] bootstrap  The application could not boot: Unsafe configuration:
             APP_DEBUG must be false when APP_ENV=production...
```

The second case is the interesting one: the debug guard added in Wave 9 refuses to boot, and
the doctor reports that refusal as its own failure rather than crashing.

---

## Two lies caught while writing the runbook

Writing the runbook exposed two things the code did not do, which is the point of writing a
procedure before believing the system is deployable.

**1. `config/migration.php` and `config/mail.php` did not exist.** The providers read
`migration.source_dsn` and `mail.from_address` from config, and `ConfigLoader` had no mapping
for them. An operator could have set `MAIL_FROM_ADDRESS` in `.env` and seen nothing happen --
the notification emails would go out with an empty sender and be filed as spam. Both config
files now exist and `ConfigLoader::applyEnvironment()` maps the keys.

**2. `bin/seed.php --reseed-roles` did not exist.** I wrote the upgrade step into the runbook
first, then checked, and found the flag absent -- so there was **no supported way** for an
operator to re-create the capability matrix after an upgrade. That is the trap that produced a
baffling `403 Missing capability` twice during development. Implemented, and it also now boots
through the real composition root: the script used `Application::create()`, which is the same
Core-only drift that once broke `bin/cron.php`.

Also corrected: the runbook originally said to create the first site with `bin/seed.php`, but
no seeder exists. The honest procedure is a documented PHP snippet, verified end to end
(site, role seeding, user, role assignment, domain map rebuild, then cleanup). Marked in the
runbook as a known gap rather than dressed up as a feature.

---

## CI pipeline

```text
lint (8.1, 8.2, 8.3)      every PHP file parses, parallel, seconds
unit (8.1, 8.2, 8.3)      no database, one second
full (8.3 + MySQL 8)      migrate -> rollback -> migrate -> doctor -> full suite
```

Three deliberate choices:

- **Rollback then re-apply** before the suite. A migration that cannot be undone is a
  migration nobody dares run in production, so CI proves it on every run.
- **`bin/doctor.php` between migrate and test.** A pipeline that reaches the tests on a
  misconfigured environment is testing something other than the code.
- **Database named `line_cms_test`.** The harness refuses any database whose name does not end
  in `_test` or `_testing`; naming it anything else would fail with a guard message that looks
  like a harness bug.

Not verified locally: the workflow itself has never run. There is no git repository in this
working copy, so it cannot be pushed or executed here. It is written to run, not proven to
run, and the first push is the real test.

---

## Tests added

- `DoctorTest` (18 tests, 29 assertions): version floor, case-insensitive extension matching,
  the PDO regression, debug guard incl. `PRODUCTION`/`Production`, `APP_KEY` empty vs short,
  non-InnoDB tables as a failure vs a non-InnoDB default as a warning, pending migrations,
  unwritable paths, uploads guard, sitemap warning, summary counts.

## Tests passed

```text
PHPUnit 11.5.57 - PHP 8.3.6
OK (1132 tests, 3516 assertions)
```

---

## Known limitations

1. **The CI workflow has never executed** (no git repository here).
2. **First-run site creation has no CLI.** Documented as a verified snippet; a proper
   `bin/site-create.php` would be better.
3. **`bin/doctor.php` cannot check** cron scheduling, the vhost `DocumentRoot`, DNS, HTTPS or
   whether Apache honours `public/uploads/.htaccess`. Those stay on the human checklist.
4. **No `robots.txt`.** Same class of gap as the sitemap, still open.
5. **No backup automation**, only the documented list of what to back up.
6. **The CI mysql service uses MySQL 8.0**; the development target is MySQL 8.3 / MariaDB.
   Engine behaviour that differs between them would not be caught.

---

## CONTRACT_CHANGE_REQUEST

none

`ConfigLoader` gained a mapping for two config namespaces that already had providers reading
them. Additive; no frozen contract touched.

---

## Ready to merge

yes
