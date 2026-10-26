# Repository Bootstrap Specification

**Project:** Lightweight Portal CMS  
**Version:** 1.0  
**Purpose:** Membuat repository awal yang siap digunakan seluruh coding agent  
**Runtime:** PHP 8.x + MySQL/MariaDB + Apache  
**Architecture:** Modular Monolith  
**Deployment:** Shared Hosting  
**Production Node.js Requirement:** None

---

# 1. Bootstrap Objective

Repository awal harus menyediakan foundation minimum untuk:

```text
Application boot
Routing
Configuration
Database
Migration
Event system
Logging
Site resolution skeleton
Authentication skeleton
Cache contract
Testing
```

Repository bootstrap **tidak boleh** langsung berisi seluruh fitur CMS.

Target milestone:

```text
HTTP Request
   ↓
Bootstrap
   ↓
Router
   ↓
Controller
   ↓
Service
   ↓
Repository
   ↓
Database
   ↓
Response
```

---

# 2. Root Structure

```text
cms/
│
├── app/
│   ├── Core/
│   ├── Modules/
│   └── Shared/
│
├── api/
├── admin/
├── bin/
├── config/
├── database/
├── docs/
├── plugins/
├── public/
├── storage/
├── tests/
├── themes/
│
├── composer.json
├── phpunit.xml
├── .env.example
├── .gitignore
└── README.md
```

---

# 3. Application Structure

```text
app/
├── Core/
│   ├── Bootstrap/
│   ├── Cache/
│   ├── Config/
│   ├── Container/
│   ├── Database/
│   ├── Events/
│   ├── Exceptions/
│   ├── Filesystem/
│   ├── Http/
│   ├── Logging/
│   ├── Routing/
│   ├── Security/
│   └── Support/
│
├── Modules/
│   ├── Site/
│   ├── Identity/
│   ├── Content/
│   ├── Taxonomy/
│   ├── Editorial/
│   ├── Revision/
│   ├── Media/
│   ├── Seo/
│   ├── Cache/
│   ├── Block/
│   ├── Theme/
│   ├── Plugin/
│   ├── Comment/
│   ├── Search/
│   ├── Notification/
│   ├── Advertising/
│   └── Migration/
│
└── Shared/
    ├── Contracts/
    ├── DTO/
    ├── Enums/
    ├── Exceptions/
    └── ValueObjects/
```

Module directory boleh masih kosong saat bootstrap.

---

# 4. Public Directory

```text
public/
├── index.php
├── .htaccess
├── assets/
└── uploads/
```

`public/` merupakan satu-satunya directory aplikasi yang idealnya dapat diakses web.

---

# 5. Storage Structure

```text
storage/
├── cache/
│   ├── pages/
│   ├── objects/
│   ├── generated/
│   ├── dependencies/
│   └── system/
│
├── logs/
├── sessions/
├── temp/
└── locks/
```

Semua directory harus writable oleh PHP.

---

# 6. Database Structure

```text
database/
├── migrations/
├── seeds/
└── fixtures/
```

Example:

```text
database/migrations/
20261024000100_create_sites.php
20261024000200_create_site_domains.php
```

---

# 7. Documentation Structure

```text
docs/
├── architecture/
├── api/
├── contracts/
├── adr/
└── agents/
```

Suggested:

```text
docs/
├── PRD.md
├── architecture/
│   └── technical-architecture.md
├── contracts/
│   ├── database.md
│   └── api-services.md
├── adr/
│   ├── ADR-001-modular-monolith.md
│   ├── ADR-002-file-cache.md
│   ├── ADR-003-multisite-isolation.md
│   └── ADR-004-block-json.md
└── agents/
    ├── agent-01-core.md
    ├── agent-02-multisite.md
    └── ...
```

---

# 8. Composer

Composer diperbolehkan untuk:

```text
autoload
testing
small focused libraries
```

Composer tidak wajib tersedia di production.

Release package harus sudah memiliki:

```text
/vendor
```

---

# 9. `composer.json`

Baseline:

```json
{
  "name": "portal/lightweight-cms",
  "description": "Lightweight cache-first publishing CMS",
  "type": "project",
  "require": {
    "php": ">=8.2"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.0"
  },
  "autoload": {
    "psr-4": {
      "CMS\\": "app/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Tests\\": "tests/"
    }
  }
}
```

Tidak menambahkan framework besar.

---

# 10. Namespace Convention

```text
CMS\Core\...
CMS\Modules\Site\...
CMS\Modules\Content\...
CMS\Shared\Contracts\...
```

Example:

```php
namespace CMS\Core\Routing;
```

---

# 11. Environment File

`.env.example`:

```text
APP_ENV=production
APP_DEBUG=false
APP_KEY=

DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

APP_BASE_PATH=
STORAGE_PATH=

SESSION_LIFETIME=120
```

Secret production tidak masuk repository.

---

# 12. Configuration Files

```text
config/
├── app.php
├── database.php
├── cache.php
├── security.php
├── media.php
└── logging.php
```

---

# 13. Config Contract

```php
namespace CMS\Shared\Contracts;

interface ConfigInterface
{
    public function get(
        string $key,
        mixed $default = null
    ): mixed;

    public function has(string $key): bool;
}
```

Usage:

```php
$config->get('database.host');
```

Dilarang menyebar:

```php
getenv('DB_HOST');
```

di seluruh module.

---

# 14. Bootstrap Entry

Create:

```text
app/Core/Bootstrap/Application.php
```

Contract:

```php
final class Application
{
    public function boot(): void;

    public function handle(
        Request $request
    ): Response;
}
```

---

# 15. Public Entry Point

`public/index.php` harus sangat kecil.

Pseudo:

```php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$app = require dirname(__DIR__) . '/app/bootstrap.php';

$response = $app->handle(
    Request::fromGlobals()
);

$response->send();
```

---

# 16. Main Bootstrap File

Create:

```text
app/bootstrap.php
```

Responsible only for:

```text
base paths
environment
config
container
core services
application instance
```

Tidak boleh memuat semua feature module secara eager tanpa perlu.

---

# 17. Request Object

Create:

```text
app/Core/Http/Request.php
```

Minimum:

```php
final class Request
{
    public function method(): string;

    public function path(): string;

    public function host(): string;

    public function query(string $key, mixed $default = null): mixed;

    public function input(string $key, mixed $default = null): mixed;

    public function header(string $name): ?string;

    public function cookie(string $name): ?string;
}
```

---

# 18. Response Object

Create:

```text
app/Core/Http/Response.php
```

Minimum:

```php
final class Response
{
    public function __construct(
        string $body = '',
        int $status = 200,
        array $headers = []
    );

    public function send(): void;
}
```

Convenience:

```text
JsonResponse
HtmlResponse
RedirectResponse
```

boleh dibuat.

---

# 19. Router

Structure:

```text
app/Core/Routing/
├── Router.php
├── Route.php
└── RouteCollection.php
```

Required:

```text
GET
POST
PUT
PATCH
DELETE
HEAD
```

Route example:

```php
$router->get(
    '/api/v1/health',
    [HealthController::class, 'show']
);
```

---

# 20. Route Parameters

Support:

```text
/api/v1/content/{id}
```

Router returns:

```text
id
```

as route parameter.

---

# 21. Named Routes

Recommended:

```php
$router->get(
    '/login',
    ...,
    name: 'login'
);
```

Needed later for:

```text
UrlGenerator
admin links
frontend links
```

---

# 22. Middleware Pipeline

Structure:

```text
app/Core/Http/Middleware/
```

Baseline:

```text
RequestIdMiddleware
ExceptionMiddleware
SiteResolutionMiddleware
SessionMiddleware
CsrfMiddleware
AuthenticationMiddleware
```

Not every middleware runs on every route.

---

# 23. Middleware Interface

```php
interface MiddlewareInterface
{
    public function process(
        Request $request,
        RequestHandlerInterface $next
    ): Response;
}
```

---

# 24. Service Container

Create lightweight container:

```text
app/Core/Container/Container.php
```

Required:

```php
bind()
singleton()
instance()
get()
has()
```

Avoid reflection-heavy autowiring as mandatory mechanism.

Explicit registration preferred.

---

# 25. Core Service Provider Pattern

Create:

```text
app/Core/Bootstrap/ServiceProviderInterface.php
```

```php
interface ServiceProviderInterface
{
    public function register(
        Container $container
    ): void;

    public function boot(
        Container $container
    ): void;
}
```

Modules can later expose service providers.

---

# 26. Database Connection

Use PDO.

Structure:

```text
app/Core/Database/
├── Connection.php
├── ConnectionFactory.php
├── TransactionManager.php
└── QueryLogger.php
```

Required PDO options:

```text
ERRMODE_EXCEPTION
EMULATE_PREPARES=false where supported
DEFAULT_FETCH_MODE=ASSOC
```

---

# 27. No ORM

Bootstrap must not introduce full ORM.

Repository implementations use:

```text
PDO
prepared statements
small query helpers
```

Goal:

```text
predictable SQL
predictable query count
minimal memory
```

---

# 28. Transaction Contract

```php
interface TransactionManagerInterface
{
    public function run(callable $callback): mixed;
}
```

Nested transaction behavior must be documented.

MVP may forbid unsupported nested transactions.

---

# 29. Query Instrumentation

Development mode should track:

```text
number of queries
total query duration
individual query duration
```

Create:

```text
QueryProfiler
```

Production can disable detailed query logging.

---

# 30. Event System

Structure:

```text
app/Core/Events/
├── EventDispatcher.php
├── ListenerRegistry.php
└── DomainEvent.php
```

Contract:

```php
interface EventDispatcherInterface
{
    public function listen(
        string $event,
        callable $listener,
        int $priority = 10
    ): void;

    public function dispatch(
        DomainEventInterface $event
    ): void;
}
```

---

# 31. Domain Event

Create immutable event object.

Required:

```text
name
occurredAt
siteId
payload
```

---

# 32. Listener Failure

Event dispatcher must support differentiation between:

```text
critical listener
non-critical listener
```

At minimum, non-critical listener exceptions must be logged.

---

# 33. Logging

Create:

```text
app/Core/Logging/
├── Logger.php
└── LogRotator.php
```

Storage:

```text
/storage/logs/app.log
```

Format should contain:

```text
timestamp
level
request_id
site_id if known
message
context
```

---

# 34. Error Handling

Production:

```text
no stack trace
no SQL
no filesystem path
```

Response:

```text
500 Internal Server Error
request_id
```

Development:

stack trace may be shown.

---

# 35. Shared Exceptions

Create:

```text
app/Shared/Exceptions/
```

Initial:

```text
ValidationException
AuthorizationException
ResourceNotFoundException
ConflictException
```

Domain agents add domain-specific exceptions.

---

# 36. Error Code Contract

Exception layer should allow:

```php
$error->code();
```

Example:

```text
CONTENT_NOT_FOUND
```

HTTP translation belongs HTTP layer.

---

# 37. Filesystem Abstraction

Provide small abstraction:

```php
interface FilesystemInterface
{
    public function exists(string $path): bool;

    public function read(string $path): string;

    public function write(
        string $path,
        string $contents
    ): void;

    public function delete(string $path): void;

    public function mkdir(string $path): void;
}
```

Used later by:

```text
cache
plugins
themes
media
logs
```

---

# 38. Atomic File Write

Cache/config generated files should use:

```text
write temp file
↓
rename
```

instead of writing directly to final file.

Prevents partially written cache.

---

# 39. File Lock Abstraction

Provide:

```php
interface LockInterface
{
    public function acquire(
        string $key,
        int $timeoutSeconds = 0
    ): bool;

    public function release(
        string $key
    ): void;
}
```

Filesystem implementation sufficient.

---

# 40. Cache Contract

Core provides interface only initially.

```php
interface CacheInterface
{
    public function has(string $key): bool;

    public function get(
        string $key,
        mixed $default = null
    ): mixed;

    public function set(
        string $key,
        mixed $value,
        int $ttl = 0
    ): void;

    public function delete(
        string $key
    ): void;

    public function clearNamespace(
        string $namespace
    ): void;
}
```

Agent 09 owns advanced implementation.

Core MAY provide simple `ArrayCache` for tests.

---

# 41. SiteContext Contract Placeholder

Before Agent 02:

```php
interface SiteContextInterface
{
    public function id(): int;

    public function domain(): string;

    public function isResolved(): bool;
}
```

Core must not provide fake production SiteContext.

---

# 42. Actor Contract Placeholder

Before Agent 03:

```php
interface ActorInterface
{
    public function id(): ?int;

    public function siteId(): int;

    public function authenticated(): bool;

    public function can(
        string $capability,
        mixed $resource = null
    ): bool;
}
```

---

# 43. Migration Runner

Create:

```text
bin/migrate.php
```

Functions:

```text
discover pending migrations
acquire migration lock
execute in order
record success
release lock
```

---

# 44. Migration Class Contract

```php
interface MigrationInterface
{
    public function up(
        Connection $db
    ): void;

    public function down(
        Connection $db
    ): void;
}
```

---

# 45. Migration Naming

```text
YYYYMMDDHHMMSS_description.php
```

Example:

```text
20261024000100_create_migrations_table.php
```

---

# 46. Migration Tracking Table

First bootstrap migration:

```text
migrations
```

must support:

```text
migration
batch
executed_at
```

---

# 47. Seed Runner

Optional but recommended:

```text
bin/seed.php
```

Used for:

```text
development site
default capabilities
default roles
test users
```

Production seeding harus explicit.

---

# 48. Cron Entry

Create:

```text
bin/cron.php
```

Initial version may only provide job runner shell.

Future jobs:

```text
scheduled publishing
notifications
temp cleanup
log cleanup
```

---

# 49. Cron Lock

`bin/cron.php` must prevent overlapping execution.

Use filesystem lock.

---

# 50. API Entry

API does not need second web root.

Routes can be registered under:

```text
/api/v1
```

through same `public/index.php`.

---

# 51. Admin Entry

Admin should also use same application.

Example:

```text
/admin
/admin/login
/admin/posts
```

SPA asset handling can be added later.

Do not duplicate PHP bootstrap.

---

# 52. `.htaccess`

For Apache:

```apache
RewriteEngine On

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

Additional security rules should block execution in uploads.

---

# 53. Upload Execution Protection

Within public uploads:

```apache
<FilesMatch "\.(php|phtml|phar|php[0-9]*)$">
    Require all denied
</FilesMatch>
```

Exact shared-host compatibility should be tested.

---

# 54. Sensitive Directory Protection

If project cannot use separate document root and must live under `public_html`, protect:

```text
/app
/config
/database
/storage
/tests
/vendor metadata where appropriate
/.env
```

---

# 55. Git Ignore

`.gitignore` should include:

```text
.env
/storage/logs/*
/storage/cache/*
/storage/sessions/*
/storage/temp/*
/public/uploads/*
.phpunit.cache
```

Keep `.gitkeep` where needed.

---

# 56. Theme Placeholder

Create:

```text
themes/.gitkeep
```

Do not build full theme in bootstrap.

Optional minimal `default` theme can come later with Agent 12.

---

# 57. Plugin Placeholder

Create:

```text
plugins/.gitkeep
```

Do not bootstrap arbitrary plugin code before Agent 14A defines lifecycle.

---

# 58. Test Structure

```text
tests/
├── Unit/
├── Integration/
├── Contract/
├── Performance/
├── Security/
└── Support/
```

---

# 59. PHPUnit

`phpunit.xml` should define:

```text
APP_ENV=testing
APP_DEBUG=true
```

Testing database must be separate.

---

# 60. Test Database

Recommended:

```text
cms_test
```

Test runner MUST NOT run destructive migrations against production database.

Safety check required:

```text
APP_ENV === testing
```

for reset helpers.

---

# 61. Core Unit Tests

Agent 01 must initially provide tests for:

```text
Config
Container
Router
Middleware
Events
Transaction
Filesystem
Lock
Logger
```

---

# 62. Core Integration Test

First integration test:

```text
GET /api/v1/health
```

Expected:

```json
{
  "data": {
    "status": "ok"
  },
  "meta": {}
}
```

---

# 63. Health Endpoint

Health endpoint must not disclose:

```text
database password
filesystem paths
full PHP configuration
```

---

# 64. Request ID

Each request receives ID.

Example:

```text
req_4f781d...
```

Return header:

```text
X-Request-ID
```

Logger includes same ID.

---

# 65. Security Headers

Core MAY provide basic defaults:

```text
X-Content-Type-Options: nosniff
Referrer-Policy
```

CSP can be added carefully later because admin/plugin scripts may complicate policy.

---

# 66. CSRF Contract Skeleton

Core should provide interface/framework:

```php
interface CsrfTokenManagerInterface
{
    public function generate(): string;

    public function validate(
        string $token
    ): bool;
}
```

Agent 03 can integrate with authenticated sessions.

---

# 67. Session Wrapper

Avoid scattered direct use of:

```php
$_SESSION
```

Provide:

```php
interface SessionInterface
{
    public function get(
        string $key,
        mixed $default = null
    ): mixed;

    public function set(
        string $key,
        mixed $value
    ): void;

    public function remove(
        string $key
    ): void;

    public function regenerate(): void;
}
```

---

# 68. Clock Abstraction

Recommended:

```php
interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
```

Useful for:

```text
scheduling
reset expiry
tests
publishing
notifications
```

Avoid direct `new DateTimeImmutable()` everywhere.

---

# 69. UTC Clock

Production implementation:

```text
UTC
```

Always.

Timezone conversion belongs presentation layer.

---

# 70. JSON Utility

Provide centralized safe JSON helper.

Must use:

```text
JSON_THROW_ON_ERROR
```

Do not silently swallow invalid JSON.

---

# 71. URL Normalization Utility

Core MAY provide low-level path normalization.

Domain-specific permalink logic belongs Content/Site modules.

---

# 72. Bootstrap Performance Rule

Core bootstrap must avoid eager instantiation.

This is especially important for:

```text
database connection
plugin manager
media processor
SEO
admin services
```

If full-page cache eventually hits before DB use, DB connection should ideally never be established.

---

# 73. Lazy Database Connection

`Connection` SHOULD establish actual PDO connection only on first use where practical.

This supports:

```text
page cache HIT
→ 0 database connection
```

---

# 74. Module Registration

Create module provider registry.

Example:

```php
$providers = [
    CoreServiceProvider::class,
    SiteServiceProvider::class,
];
```

Future agents add providers.

Do not manually edit dozens of bootstrap files.

---

# 75. Module Provider Contract

```php
interface ModuleServiceProviderInterface
{
    public function register(
        Container $container
    ): void;

    public function boot(
        Container $container
    ): void;
}
```

---

# 76. Routes per Module

Each module should own its routes.

Example:

```text
app/Modules/Content/Http/routes.php
```

Aggregator loads route files.

API Agent may coordinate central HTTP route registration later.

---

# 77. No Business Logic in Route Files

Allowed:

```php
$router->post(
    '/api/v1/content',
    [ContentController::class, 'store']
);
```

Forbidden:

```php
$router->post('/...', function () use ($db) {
    $db->query(...);
});
```

---

# 78. Repository Contracts Location

Shared public repository contracts should live in module/domain or shared contracts depending on ownership.

Example:

```text
app/Modules/Content/Domain/ContentRepositoryInterface.php
```

not necessarily all dumped into one giant Shared folder.

---

# 79. Shared Folder Rule

`app/Shared` only contains concepts genuinely used across multiple domains.

Do not turn Shared into miscellaneous dumping ground.

---

# 80. README

Root README should include:

```text
requirements
installation
local setup
migration command
test command
directory structure
architecture principles
production deployment
```

---

# 81. Local Development Setup

Example:

```text
composer install
copy .env.example .env
configure database
php bin/migrate.php
php -S localhost:8080 -t public
```

Node not needed until admin frontend work starts.

---

# 82. Production Build

Release process:

```text
composer install --no-dev --optimize-autoloader
build admin assets if applicable
package application
upload via FTP/File Manager
run migrations
```

Production server itself need not run Composer.

---

# 83. Release Package

Should include:

```text
app
api
admin
bin
config
database
plugins
public
storage required directories
themes
vendor
```

Should exclude:

```text
tests
development logs
.git
node_modules
source maps if not desired
```

---

# 84. Production Install Check

Create eventually:

```text
bin/check-environment.php
```

Checks:

```text
PHP version
PDO MySQL
GD/Imagick availability
WebP support
writable storage
writable uploads
database connectivity
Apache rewrite assumptions where possible
```

Missing optional dependency must be distinguished from fatal requirement.

---

# 85. Required PHP Extensions

Minimum likely:

```text
PDO
pdo_mysql
json
mbstring
fileinfo
session
```

For media:

```text
GD or Imagick
```

Exact list must be confirmed during environment testing.

---

# 86. Graceful Optional Capabilities

Example:

If Imagick absent:

```text
use GD
```

If WebP unsupported:

```text
do not fail entire upload
```

If cron unavailable:

future lazy scheduler fallback.

---

# 87. Shared Contract Version

Create constant:

```php
final class ContractVersion
{
    public const MAJOR = 1;
}
```

Plugins may later verify compatibility.

---

# 88. Architecture Tests

Recommended test enforcing forbidden dependencies.

Examples:

```text
Theme namespace must not import Core\Database
Block renderer must not import Repository
HTTP controllers must not import PDO
```

Can be implemented using lightweight static file scanning initially.

---

# 89. Dependency Rule Test

A simple architecture test can scan PHP source for forbidden imports.

Example:

```text
CMS\Modules\Theme
must not depend on
CMS\Core\Database
```

This is valuable in multi-agent development.

---

# 90. Agent Ownership File

Create:

```text
docs/agents/OWNERSHIP.md
```

Example:

```text
/app/Core → Agent 01
/app/Modules/Site → Agent 02
/app/Modules/Identity → Agent 03
...
```

Agents should not modify another owner's files without explicit coordination.

---

# 91. Contract Change File

Create:

```text
docs/contracts/CHANGELOG.md
```

Every frozen contract change recorded.

Format:

```text
Version
Date
Contract
Change
Breaking?
Affected agents
Migration required?
```

---

# 92. ADR Requirement

Any architecture-level decision must create:

```text
docs/adr/ADR-XXX-name.md
```

Initial ADRs:

```text
ADR-001 Modular Monolith
ADR-002 Lightweight PHP Core
ADR-003 File Cache
ADR-004 Multisite SiteContext
ADR-005 Block JSON + Compiled HTML
ADR-006 SSR Public Frontend
ADR-007 Custom Plugin API
```

---

# 93. Initial Core Files

Agent 01 should initially create at minimum:

```text
app/bootstrap.php

app/Core/Bootstrap/Application.php
app/Core/Bootstrap/CoreServiceProvider.php

app/Core/Config/Config.php
app/Core/Container/Container.php

app/Core/Database/Connection.php
app/Core/Database/ConnectionFactory.php
app/Core/Database/TransactionManager.php

app/Core/Events/EventDispatcher.php
app/Core/Events/DomainEvent.php

app/Core/Http/Request.php
app/Core/Http/Response.php
app/Core/Http/JsonResponse.php

app/Core/Routing/Router.php
app/Core/Routing/Route.php

app/Core/Logging/Logger.php

app/Core/Filesystem/Filesystem.php
app/Core/Filesystem/FileLock.php

public/index.php

bin/migrate.php
bin/cron.php

config/app.php
config/database.php
config/cache.php

tests/bootstrap.php
```

---

# 94. Initial Shared Contracts

Create:

```text
ConfigInterface
LoggerInterface
FilesystemInterface
LockInterface
CacheInterface
EventDispatcherInterface
DomainEventInterface
TransactionManagerInterface
SiteContextInterface
ActorInterface
ClockInterface
SessionInterface
```

These are contract-only where downstream agent owns concrete implementation.

---

# 95. First Database Migration

Create:

```text
migrations
```

tracking table first.

Then Agent 02 takes ownership of:

```text
sites
site_domains
site_settings
```

---

# 96. First Vertical Slice

Before adding advanced modules, repository should support:

```text
GET /api/v1/health
```

and:

```text
GET /
```

with simple placeholder response.

This proves:

```text
Apache rewrite
bootstrap
router
response
logging
request ID
```

work.

---

# 97. Second Vertical Slice

After Agent 02:

```text
request host
↓
SiteResolver
↓
SiteContext
↓
controller
```

Site-specific response:

```text
Site resolved successfully.
```

---

# 98. Third Vertical Slice

After Agent 03:

```text
login
↓
session
↓
Actor
↓
capability
```

---

# 99. Fourth Vertical Slice

After Agent 04:

```text
create article
↓
database
↓
retrieve article
```

Only after this should larger parallelization expand.

---

# 100. Fifth Vertical Slice

Then:

```text
publish
↓
render
↓
cache
↓
second request
↓
0 database queries
```

This is the most important architecture validation milestone.

---

# 101. Bootstrap Definition of Done

Repository bootstrap is complete when:

1. application boots;
2. router works;
3. request/response abstraction works;
4. middleware works;
5. container works;
6. environment/config works;
7. PDO connection works;
8. transactions work;
9. migrations work;
10. event dispatcher works;
11. logger works;
12. filesystem/locks work;
13. tests run;
14. health endpoint passes;
15. production errors hide internals;
16. repository structure matches ownership model;
17. shared contracts exist;
18. architecture rules documented;
19. no framework dependency exists;
20. application can be packaged for shared hosting.

---

# 102. First Agent to Execute

After repository skeleton and documents are available, the first implementation agent should be:

**Agent 01 — Core Platform**

Agent 01 must not implement CMS features yet.

Its only job is to make the repository capable of safely accepting work from all subsequent agents.

---

# 103. Recommended Git Milestones

```text
bootstrap/initial-structure
core/runtime
core/database
core/events
core/http
core/testing
multisite/site-context
identity/auth
content/foundation
```

Prefer small, reviewable merges rather than one massive initial commit.

---

# 104. North Star

Repository bootstrap succeeds when a future agent can open the repository and immediately know:

```text
where its files belong
which interfaces it must use
which files it may modify
how database migrations work
how events work
how tests run
how site context works
how errors are represented
how its module integrates
```

The agent should not need to invent a second architecture.