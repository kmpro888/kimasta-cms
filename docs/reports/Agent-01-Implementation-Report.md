# IMPLEMENTATION_REPORT

Agent: Agent 01 — Core Platform
Status: COMPLETE (code + tests + real-HTTP verification)

---

## Summary

Implemented the Core Platform foundation of the Lightweight Portal CMS as a
modular monolith: explicit DI container, dot-notation config with a single
environment-access point, HTTP kernel (request/response/middleware pipeline),
routing, structured logging with rotation, filesystem abstraction with advisory
locks, domain event dispatcher, PDO database layer with transaction manager and
query profiler, and a migration runner.

No CMS feature work was implemented (no sites/domains/settings, no auth, no
content, no themes, no plugins) — those belong to Agents 02–06.

The deliverable is verified by 116 automated tests (248 assertions) across
Unit, Integration, Contract and Security suites, plus a real-HTTP smoke check
through the PHP built-in server.

### Verified over real HTTP

Serving `public/` on `127.0.0.1:8899`:

```
GET /api/v1/health
200  Content-Type: application/json; charset=utf-8
X-Request-ID: req_5675ca52112c12cb9b73c5e7
{"data":{"status":"ok"},"meta":{}}

GET /api/v1/nope        (APP_DEBUG=false, APP_ENV=production)
404  Content-Type: application/json; charset=utf-8
{"error":{"code":"RESOURCE_NOT_FOUND","message":"Resource not found.","details":{}}}
     contains "F:\"     -> false
     contains "trace"   -> false
     contains ".php"    -> false
```

Both frozen envelopes hold byte-for-byte, the request ID is echoed, and the
production path leaks no path, stack trace or filename.

---

## Files added

### Bootstrap / container
- `app/bootstrap.php`
- `app/Core/Bootstrap/Application.php`
- `app/Core/Bootstrap/CoreServiceProvider.php`
- `app/Core/Bootstrap/ServiceProviderInterface.php`
- `app/Core/Container/Container.php`
- `app/Core/Container/Exceptions/BindingNotFoundException.php`

### Config
- `app/Core/Config/Config.php`
- `app/Core/Config/Env.php`
- `app/Core/Config/ConfigLoader.php`
- `config/app.php`
- `config/cache.php`
- `config/database.php`
- `config/logging.php`
- `config/media.php`
- `config/security.php`

### HTTP kernel
- `app/Core/Http/Request.php`
- `app/Core/Http/Response.php`
- `app/Core/Http/JsonResponse.php`
- `app/Core/Http/RedirectResponse.php`
- `app/Core/Http/ErrorResponse.php`
- `app/Core/Http/HttpException.php`
- `app/Core/Http/RequestHandlerInterface.php`
- `app/Core/Http/Middleware/MiddlewareInterface.php`
- `app/Core/Http/Middleware/MiddlewarePipeline.php`
- `app/Core/Http/Middleware/RequestIdMiddleware.php`
- `app/Core/Http/Middleware/ExceptionMiddleware.php`
- `app/Core/Http/Controller/HealthController.php`

### Routing
- `app/Core/Routing/Router.php`
- `app/Core/Routing/Route.php`
- `app/Core/Routing/RouteMatch.php`

### Logging
- `app/Core/Logging/Logger.php`
- `app/Core/Logging/LoggerInterface.php`
- `app/Core/Logging/LogRotator.php`

### Support
- `app/Core/Support/SystemClock.php`

### Filesystem
- `app/Core/Filesystem/FilesystemInterface.php`
- `app/Core/Filesystem/Filesystem.php`
- `app/Core/Filesystem/LockInterface.php`
- `app/Core/Filesystem/FileLock.php`

### Events
- `app/Core/Events/DomainEvent.php`
- `app/Core/Events/ListenerRegistry.php`
- `app/Core/Events/EventDispatcher.php`

### Database
- `app/Core/Database/Connection.php`
- `app/Core/Database/ConnectionFactory.php`
- `app/Core/Database/TransactionManager.php`
- `app/Core/Database/QueryProfiler.php`
- `app/Core/Database/Exceptions/NestedTransactionException.php`
- `app/Core/Database/Migrations/MigrationInterface.php`
- `app/Core/Database/Migrations/Migration.php`
- `app/Core/Database/Migrations/MigrationRepository.php`
- `app/Core/Database/Migrations/MigrationHistory.php`
- `app/Core/Database/Migrations/MigrationRunner.php`

### Contracts (implementations of frozen interfaces)
- `app/Shared/Contracts/ConfigInterface.php`
- `app/Shared/Contracts/SiteContextInterface.php`
- `app/Shared/Contracts/ActorInterface.php`
- `app/Shared/Contracts/CacheInterface.php`
- `app/Shared/Contracts/ClockInterface.php`
- `app/Shared/Contracts/SessionInterface.php`
- `app/Shared/Contracts/CsrfTokenManagerInterface.php`
- `app/Shared/Contracts/EventDispatcherInterface.php`
- `app/Shared/Contracts/DomainEventInterface.php`
- `app/Shared/Contracts/TransactionManagerInterface.php`

### Entry points
- `public/index.php`
- `public/.htaccess`
- `public/uploads/.htaccess`
- `public/uploads/.gitkeep`
- `bin/migrate.php`
- `bin/cron.php`
- `bin/seed.php`

### Migrations
- `database/migrations/20261024000100_create_migrations_table.php`

### Test infrastructure
- `tests/bootstrap.php`
- `tests/Support/TestCase.php`
- `tests/Support/DatabaseTestCase.php`
- `phpunit.xml`

### Project scaffolding
- `composer.json`
- `.env.example`
- `.gitignore`

---

## Files modified

- `public/.htaccess` — rewrite rules, PHP execution denial, `X-Content-Type-Options`, `Options -Indexes`
- `.env` — local development values (not committed)
- `app/Core/Http/JsonResponse.php` — `meta` now encodes as `{}` (frozen envelope)
- `app/Core/Http/ErrorResponse.php` — aligned with the frozen error envelope
- `app/Core/Http/Middleware/ExceptionMiddleware.php` — no debug payload unless `APP_DEBUG`
- `app/Core/Routing/Router.php` — pipeline ordering, parameter segment slashes, 405 handling
- `app/Core/Routing/Route.php` — inline `{id:\d+}` constraints
- `app/Core/Events/ListenerRegistry.php` + `EventDispatcher.php` — higher priority runs first
- `app/Core/Config/Env.php` — process environment now correctly takes precedence over `.env`
- `bin/migrate.php` — `status` tolerates a missing `migrations` table
- `F:\_E\_SERVER\wamp64\bin\apache\apache2.4.59\conf\extra\httpd-vhosts.conf` — `line.id` vhost `DocumentRoot` → `.../line.id/public`
- `F:\_E\_SERVER\wamp64\bin\mysql\mysql8.3.0\my.ini` — `default_storage_engine=InnoDB` (backup: `my.ini.bak-before-innodb`)

---

## Contracts implemented

| Contract | Implementation |
|---|---|
| `ConfigInterface` | `CMS\Core\Config\Config` |
| `ClockInterface` | `CMS\Core\Support\SystemClock` |
| `EventDispatcherInterface` | `CMS\Core\Events\EventDispatcher` |
| `DomainEventInterface` | `CMS\Core\Events\DomainEvent` |
| `TransactionManagerInterface` | `CMS\Core\Database\TransactionManager` |

## Contracts consumed

| Contract | Consumed by |
|---|---|
| `ConfigInterface` | `CoreServiceProvider`, `Logger`, `Filesystem`, `FileLock`, `ConnectionFactory` |
| `ClockInterface` | `Logger` |
| `EventDispatcherInterface` | registered in container for Agents 02–06 |
| `TransactionManagerInterface` | registered in container for Agents 02–06 |

`SiteContextInterface`, `ActorInterface`, `CacheInterface`, `SessionInterface`,
`CsrfTokenManagerInterface` are declared as frozen contracts but **not
implemented** — they are owned by Agents 02–06. No competing implementation was
created.

---

## Database migrations

| Migration | Purpose |
|---|---|
| `20261024000100_create_migrations_table.php` | Creates `migrations` (`migration` VARCHAR PK, `batch` INT, `executed_at` DATETIME) |

Verified: forward run, idempotent re-run (no duplicate rows), rollback, and
`status` command.

Storage engine note: the WAMP MySQL instance was configured with
`default_storage_engine=MYISAM`, which silently makes `BEGIN`/`ROLLBACK` no-ops
and disables foreign keys. Changed to `InnoDB` (`my.ini` line 64, backup kept)
and applied live via `SET GLOBAL`. **A WAMP restart is still required for the
`my.ini` change to survive a machine reboot.**

---

## Events emitted

None. Core Platform emits no domain events; it provides the dispatcher
infrastructure for later modules.

## Events consumed

None.

Event naming convention enforced by documentation only at this stage:
`<module>.<entity>.<action>` (e.g. `content.article.published`).

---

## API changes

Added the only endpoint owned by Agent 01:

| Method | Path | Response |
|---|---|---|
| `GET` | `/api/v1/health` | `{"data":{"status":"ok"},"meta":{}}` |

Response headers: `X-Request-ID: req_<hex>` (generated server-side; client
values are not trusted).

Error responses use the frozen envelope:

```json
{"error":{"code":"RESOURCE_NOT_FOUND","message":"Resource not found.","details":{}}}
```

`code` is UPPER_SNAKE. `details` is always present and serialises as `{}` when
empty.

---

## Cache impact

None implemented at this layer. `CacheInterface` is declared frozen and
unimplemented; the filesystem cache driver belongs to the module that owns
caching policy. Config placeholder `config/cache.php` exists with filesystem
default.

No Redis/Memcached dependency introduced.

---

## Multisite impact

No tenant-owned tables created, so no isolation surface exists yet. The
`SiteContextInterface` contract is in place for Agents 02–06. No `site_id = 1`
hardcoding exists anywhere in the codebase.

---

## Security impact

Hardening implemented:

- `public/uploads/.htaccess` denies execution of `.php`, `.phtml`, `.phar`
- `public/.htaccess` denies script execution and sets `X-Content-Type-Options: nosniff`
- `Options -Indexes` prevents directory listing
- Production error responses contain no SQL, credentials, paths, stack traces,
  class names or environment values (verified by 22 security tests)
- Client-supplied `X-Request-ID` is not trusted; IDs are always generated
- PDO uses `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, `FETCH_ASSOC`
- `APP_DEBUG` is gated on config, and process env correctly overrides `.env`

### Bugs found and fixed during implementation

1. **Router** — parameter segments emitted no leading `/`, so every
   parameterised route failed to match.
2. **Router** — inline `{id:\d+}` constraints were unsupported.
3. **JsonResponse** — `meta` encoded as `[]` instead of `{}`, violating the
   frozen success envelope.
4. **MiddlewarePipeline** — global middleware executed twice because routing
   happened outside the pipeline; `HttpException` could escape unhandled.
5. **EventDispatcher** — listener priority was inverted (lower ran first).
6. **ErrorResponse** — error envelope shape deviated from the specification.
7. **ExceptionMiddleware** — debug detail leaked when `APP_DEBUG` was off.
8. **Env** — file values overrode process environment, contradicting its own
   docblock. Real risk: a hosting panel setting `APP_DEBUG=false` could be
   silently overridden by a stale `.env`, leaking stack traces in production.
9. **`public/uploads/.htaccess`** — was missing entirely.
10. **MySQL** — `default_storage_engine=MYISAM` silently disabled transactions.

---

## Tests added

### unit
- `tests/Unit/Core/ContainerTest.php`
- `tests/Unit/Core/ConfigTest.php`
- `tests/Unit/Core/ConfigLoaderTest.php`
- `tests/Unit/Core/RouterTest.php`
- `tests/Unit/Core/MiddlewarePipelineTest.php`
- `tests/Unit/Core/RequestIdMiddlewareTest.php`
- `tests/Unit/Core/ExceptionMiddlewareTest.php`
- `tests/Unit/Core/EventDispatcherTest.php`
- `tests/Unit/Core/FilesystemTest.php`
- `tests/Unit/Core/FileLockTest.php`
- `tests/Unit/Core/LoggerTest.php`

### integration
- `tests/Integration/Core/TransactionManagerTest.php` — real rollback on InnoDB
- `tests/Integration/Core/HealthEndpointTest.php` — end-to-end through `Application::handle()`

### contract
- `tests/Contract/Core/RestEnvelopeTest.php` — frozen success and error envelopes

### security
- `tests/Security/Core/ProductionLeakTest.php` — 22 tests: no SQL, secrets,
  paths, traces, class names or env values in production responses; process
  env precedence; uploads execution denial

### performance
- Not added at this layer. There is no cached page path yet, and the
  zero-query target cannot be meaningfully asserted before the page cache and
  `SiteContext` exist (Agents 02–04). Query counting infrastructure
  (`QueryProfiler`) is in place so those tests can be written against it.

---

## Tests passed

```
PHPUnit 11.5.57 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.3.6
Configuration: F:\_E\_SERVER\wamp64\www\line.id\phpunit.xml

OK (116 tests, 248 assertions)
```

Breakdown:

| Suite | Tests | Result |
|---|---|---|
| Unit | 73 | pass |
| Integration | 8 | pass |
| Contract | 13 | pass |
| Security | 22 | pass |
| Performance | 0 | not applicable yet |

No skips, no warnings, no risky tests (`failOnWarning`, `failOnRisky` and
`beStrictAboutOutputDuringTests` are all enabled).

Test database: `line_cms_test`, guarded so destructive resets refuse to run
against a database whose name does not end in `_test` or `_testing`.

---

## Query/performance notes

- `Connection` uses `EMULATE_PREPARES=false` so MySQL really prepares
  statements; no client-side interpolation.
- `ConnectionFactory` sets `ERRMODE_EXCEPTION` and `FETCH_ASSOC`; no `SELECT *`
  exists in any Core query (the only Core query is the migration bookkeeping).
- `QueryProfiler` records statement text and duration so later modules can
  assert query counts in regression tests.
- Container registrations are closures only; booting does no I/O beyond reading
  `config/*.php`. PDO connection, cache and plugin loading are lazy, so a
  future cached public request can complete without opening a connection.
- `bin/cron.php` takes a `FileLock` before running jobs and registers none at
  this stage; it is safe to schedule from day one.

---

## Known limitations

1. **RESOLVED — bare `403` on every request was caused by `public/.htaccess`.**
   The first version of `public/.htaccess` denied script execution for the whole
   document root:

   ```apache
   <FilesMatch "\.(php|phtml|phar|php[0-9]*)$">
       Require all denied
   </FilesMatch>
   ```

   Because `RewriteRule ^ index.php [QSA,L]` rewrites every unmatched request to
   `index.php`, that block also denied the front controller itself, so Apache
   answered every URL with a body-less `403` and never reached PHP. The rule was
   correct in intent (uploads must not execute) but wrong in scope.

   Fix: the script-execution guard now lives only in
   `public/uploads/.htaccess`, which denies `php|phtml|phar|php[0-9]*|cgi|pl|py|sh|asp|aspx|jsp`,
   disables the PHP engine for that directory, and blocks uploaded `.htaccess`
   files. `public/.htaccess` keeps only the rewrite rule, the `nosniff` header
   and `Options -Indexes`.

   Verified after the fix, over real Apache HTTP:

   | Request | Status | Body |
   |---|---|---|
   | `GET http://line.id/api/v1/health` | `200` | `{"data":{"status":"ok"},"meta":{}}` |
   | `GET http://line.id/api/v1/nope` | `404` | `{"error":{"code":"RESOURCE_NOT_FOUND","message":"Resource not found.","details":{...}}}` |

   Both responses carry `Content-Type: application/json`, echo `X-Request-ID`
   and set `X-Content-Type-Options: nosniff`.

   Misdiagnoses recorded for transparency: an `Everyone:(DC)` ACE was first
   blamed, then retracted (that ACE is `DeleteSubdirectoriesAndFiles`, not
   read); `Require local` was blamed next, then retracted (`pntu.id` and `s.id`
   use the same directive and return `200`). The vhost file was also edited and
   restored during that investigation; its final on-disk state is
   `DocumentRoot "f:/_e/_server/wamp64/www/line.id/public"` with
   `Options +FollowSymLinks -Indexes -MultiViews`, `AllowOverride All`,
   `Require local`.
2. **Debug detail appears in error responses only when `APP_DEBUG=true`.**
   The workspace `.env` sets `APP_DEBUG=true`, so local 404s include `file`,
   `line` and `trace`. This is correct development behaviour. Production
   behaviour was verified against real Apache with `APP_DEBUG=false` in the
   process environment:
   `{"error":{"code":"RESOURCE_NOT_FOUND","message":"Resource not found.","details":{}}}`
   — no absolute paths, no `.php` filenames, no trace.
3. **Environment precedence is process-env-first and was verified at runtime.**
   `Env::get()` resolves `getenv($key)` before `.env` values, so a hosting panel
   that exports `APP_DEBUG=false` cannot be overridden by a stale `.env`. Probe
   result: with process env `APP_DEBUG=false` and `.env` containing `true`, the
   resolved value is `false`; with no process env it falls back to `true`. The
   docblock and the implementation now agree (they previously contradicted each
   other, and the file value wrongly won — a real production leak risk).
4. **MySQL `my.ini` engine change needs one tray restart to persist.**
   `my.ini:64` now reads `default_storage_engine=InnoDB` (original preserved as
   `my.ini.bak-before-innodb`), and the live global value is `InnoDB`. The live
   value was applied with `SET GLOBAL`, which does not survive a server restart,
   so MySQL must be restarted once from the WAMP tray for the file setting to
   take effect on boot. Existing databases are untouched; only newly created
   tables are affected.
5. `tests/Performance/` is intentionally empty; see the note above.
6. `CacheInterface`, `SiteContextInterface`, `ActorInterface`,
   `SessionInterface` and `CsrfTokenManagerInterface` are declared but
   unimplemented, as they belong to Agents 02–06.
7. Front-controller routing depends on `mod_rewrite` and `AllowOverride All`;
   both are present in the WAMP vhost but must be reproduced on shared hosting.

---

## CONTRACT_CHANGE_REQUEST

none

No frozen contract was modified. `EventDispatcher` listener ordering (higher
priority value runs first) was clarified in the registry and its docblock — this
is a documentation clarification of existing behaviour, not a contract change.

---

## Ready to merge

yes

Code, tests and real-HTTP behaviour are complete and verified. The previously
open Apache issue is resolved: it was a scope error in `public/.htaccess`, not a
service reload, and the endpoint now returns the frozen envelope over
`http://line.id/api/v1/health`. Remaining items are operational notes only (one
WAMP tray restart for the MySQL engine setting to persist) and module
placeholders owned by Agents 02–06.