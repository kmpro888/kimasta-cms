# Deployment Runbook

Everything here has been done on this project at least once, and the "Troubleshooting"
section is a list of failures that actually happened, not hypothetical ones.

Verified by `php bin/doctor.php` at every step.

---

## 1. Requirements

| Component | Requirement | Why |
|---|---|---|
| PHP | 8.2 or newer (8.3 tested) | enums, readonly properties, `never` return type |
| PHP extensions | `pdo`, `pdo_mysql`, `mbstring`, `json`, `fileinfo` | required |
| PHP extensions | `gd` (or `imagick`), `exif` | image variants and EXIF; degrades without them |
| MySQL / MariaDB | 8.0+ / 10.5+ with **InnoDB** | the schema uses foreign keys and transactions |
| Apache | `mod_rewrite`, `AllowOverride All` | routing lives in `.htaccess` |
| Disk | writable `storage/` and `public/uploads/` | cache, logs, locks, uploads |
| Cron | one entry, every minute or every five | publishing, email outbox, sitemap |

No Node.js, no Redis, no queue worker, no Docker. If a host requires any of those, it is
not a supported host.

---

## 2. Before you start

```bash
php bin/doctor.php
```

Read the failures. On a fresh checkout you will legitimately see "N migration(s) pending"
and "no sitemap artifact yet" -- those resolve in step 5 and step 7.

---

## 3. Upload

Put the project **outside** the web root if the host allows it, and point the vhost at
`public/`:

```apache
<VirtualHost *:80>
    ServerName example.com
    DocumentRoot /home/account/line.id/public

    <Directory /home/account/line.id/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

`DocumentRoot` **must** be `public/`. Pointing it at the project root exposes `.env`,
`storage/` and the source tree.

---

## 4. Configure

```bash
cp .env.example .env
php -r "echo bin2hex(random_bytes(32));"     # use the output as APP_KEY
```

Fill in `.env`. Minimum set:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=<the generated value>
APP_URL=https://example.com
DB_HOST=localhost
DB_DATABASE=account_line
DB_USERNAME=account_line
DB_PASSWORD=<the database password>
LOG_LEVEL=warning
```

Three of these are load-bearing:

- **`APP_ENV=production` with `APP_DEBUG=true` refuses to boot.** That is intentional: debug
  output prints stack traces, SQL and filesystem paths to visitors.
- **`APP_KEY`** must not stay empty.
- **`LOG_LEVEL=warning`** -- `debug` on a shared host fills the disk.

Then:

```bash
chmod -R 0775 storage public/uploads
php bin/doctor.php
```

---

## 5. Database

```bash
php bin/migrate.php
```

Expected: one line per migration, then `Executed N migration(s).`

Verify the engine, because a MyISAM default accepts the migrations and then silently stops
enforcing foreign keys:

```bash
php bin/doctor.php | grep -i engine
```

If it reports non-InnoDB tables, fix the server default (`default_storage_engine=InnoDB` in
`my.ini` / `my.cnf`) and re-run the migrations from an empty database.

Create the first site and its administrator.

**There is no CLI for this yet.** It is a known gap: a fresh install cannot create its first
tenant without running PHP. The procedure below is verified and idempotent enough to re-run.

```bash
php -r '
require "vendor/autoload.php";
$app = require "app/bootstrap.php";
$app->boot();
$c = $app->container();

$sites = $c->get(CMS\Modules\Site\Contracts\SiteServiceInterface::class);
$roles = $c->get(CMS\Modules\Identity\Contracts\RoleServiceInterface::class);
$users = $c->get(CMS\Modules\Identity\Contracts\UserServiceInterface::class);

$site = $sites->create(name: "My Site", slug: "my-site", primaryDomain: "example.com");

// Roles and capabilities must exist before anything can be assigned.
$roles->seedDefaults($site->id());

$adminRoleId = null;
foreach ($roles->listForSite($site->id()) as $role) {
    if ($role->slug === "administrator") { $adminRoleId = (int) $role->id; }
}

$user = $users->create($site->id(), [
    "username" => "admin",
    "email"    => "admin@example.com",
    "password" => "CHANGE-THIS-BEFORE-FIRST-LOGIN",
    "display_name" => "Administrator",
]);

$roles->assignRole((int) $user->id, $adminRoleId, $site->id());

// The compiled domain map is what request-time tenant resolution reads.
$sites->regenerateDomainMap();

echo "site " . $site->id() . ", user " . $user->id . PHP_EOL;
'
```

Order matters: `seedDefaults()` before `assignRole()`, and `regenerateDomainMap()` last, or
the site answers 404 on its own domain.

Then log in through the API and change the password immediately:

```bash
curl -X POST https://example.com/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"admin","password":"CHANGE-THIS-BEFORE-FIRST-LOGIN"}'
```

---

## 6. Cron

One entry is enough:

```cron
* * * * * /usr/local/bin/php /home/account/line.id/bin/cron.php >> /home/account/cron.log 2>&1
```

It runs three jobs, each with its own lock so overlapping runs are safe:

| Job | Effect if missing |
|---|---|
| scheduled publishing | a scheduled article never appears |
| notification email outbox | notification emails accumulate and are never sent |
| sitemap regeneration | `/sitemap.xml` goes stale and eventually 404s |

Verify manually before trusting cron:

```bash
php bin/cron.php
```

Expected output names all three jobs:

```
Cron started at ...
Scheduled publishing: 0 item(s) published.
Notification email: 0 sent, 0 failed.
Sitemap: 12 URL(s) across 1 site(s).
Cron finished.
```

---

## 7. First request

```bash
curl -I https://example.com/
curl -I https://example.com/api/v1/health
curl -I https://example.com/sitemap.xml
```

Expected: `200`, `200`, `200`. The sitemap is `404` until cron has run once (step 6) --
that is by design, because the sitemap is a generated artifact and never a live query.

---

## 8. Upgrading an existing installation

```bash
# 1. back up the database and public/uploads
# 2. upload the new code (keep .env and storage/)
php bin/migrate.php
php bin/doctor.php
```

**Re-seed capabilities.** This is the upgrade step people forget, and it fails as a `403`
that looks like a permissions bug:

```bash
php bin/seed.php --reseed-roles            # staging / local
php bin/seed.php --reseed-roles --force    # production requires --force
```

```text
Re-seeded roles and capabilities for site 1 (my-site).
Done: 1 site(s).
```

Adding a capability to the catalogue does **not** create it in an existing database. The
seeder is idempotent, so running it on every upgrade is safe. This happened twice while
building this platform (`comment.moderate`, then `ads.manage`).

---

## 9. Rollback

```bash
php bin/migrate.php rollback     # one migration
php bin/migrate.php rollback 3   # three
```

Every migration has a `down()`, and CI verifies rollback then re-applies on every run.

If code rollback is needed, restore the previous release directory and the database dump
together -- the schema and the code move as a pair.

---

## 10. Troubleshooting

Every entry below is a real failure from this project.

| Symptom | Cause | Fix |
|---|---|---|
| **403 on every request**, HTML error from Apache | a `<FilesMatch ...php...> Require all denied` block in `public/.htaccess` blocks the rewritten front controller | remove it from `public/`; keep the execution guard only in `public/uploads/.htaccess` |
| Every URL serves the project root listing | `DocumentRoot` points at the project, not `public/` | fix the vhost, restart Apache |
| `500` with a stack trace shown to visitors | `APP_DEBUG=true` | set `APP_DEBUG=false`; the app now refuses to boot in production with it on |
| `500` on a page that worked before, storage mentioned | `storage/` not writable | `chmod -R 0775 storage` |
| `404` on every page after cutover | DNS/vhost serves a different directory, or the domain map is stale | `php bin/doctor.php`, then `bin/cron.php` |
| `403 Missing capability: X` right after an upgrade | capability added to the catalogue, never seeded | re-run the seeder (step 8) |
| Scheduled posts never publish | cron not installed | install the entry, verify with `php bin/cron.php` |
| `/sitemap.xml` returns 404 | cron has not run since deploy | run once; it is never generated on request |
| Uploaded files can be executed as PHP | `public/uploads/.htaccess` missing or overridden | restore it; `bin/doctor.php` fails without it |
| Foreign keys silently not enforced | database defaulted to MyISAM | switch to InnoDB and re-migrate |
| Login returns `429 RATE_LIMITED` | five failed attempts in five minutes from one address | wait, or clear the bucket under `storage/cache/rate-limits` |

---

## 11. What `bin/doctor.php` does not check

It cannot, from a CLI script:

- that cron is scheduled;
- that the vhost `DocumentRoot` is `public/`;
- that DNS resolves;
- that HTTPS terminates correctly;
- that `public/uploads/.htaccess` is actually honoured by the running Apache.

Those five are the checklist for a human after deploy. The first three are visible
immediately with the `curl` commands in step 7.

---

## 12. Backups

Back up three things, together:

1. the database,
2. `public/uploads/`,
3. `.env` (it holds the database password and `APP_KEY`).

`storage/` does not need backing up: it is cache, logs and locks, all regenerable. Losing
it costs a cold cache, nothing more.
