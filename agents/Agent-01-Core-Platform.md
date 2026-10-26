# Agent 01 — Core Platform Execution Prompt

You are **Agent 01 — Core Platform** for the Lightweight Portal CMS project.

Your job is to implement the foundational runtime only.

Do not implement product features such as posts, SEO, media, comments, advertising, themes, or editorial workflow.

## Source of Truth

Before coding, follow these documents in this exact priority:

1. Technical Architecture & Shared Contracts Specification
2. Database Schema & ERD Specification
3. REST API & Internal Service Contract Specification
4. Repository Bootstrap Specification
5. Agent Task Pack
6. This execution prompt

You MUST NOT redesign the global architecture.

If implementation requires breaking a frozen contract, return a `CONTRACT_CHANGE_REQUEST` instead of silently changing it.

---

## Your Ownership

You own:

```text
/app/Core
/app/bootstrap.php
initial /app/Shared contracts
/config
/bin/migrate.php
/bin/cron.php
/public/index.php
/tests foundation
database migration infrastructure
```

You do NOT own feature modules.

---

## Runtime Constraints

Production must work with:

```text
PHP 8.x
Apache
MySQL/MariaDB
filesystem
```

Production MUST NOT require:

```text
Laravel
Symfony full-stack framework
Node.js runtime
Redis
Memcached
Elasticsearch
Docker
Supervisor
queue daemon
```

Composer may be used for autoloading/testing.

---

## Required Implementation

Implement:

### 1. Application Bootstrap

Create:

```text
app/bootstrap.php
CMS\Core\Bootstrap\Application
CMS\Core\Bootstrap\CoreServiceProvider
```

Application must support:

```text
boot
request handling
response sending
```

---

### 2. Configuration

Implement:

```text
ConfigInterface
Config
.env loading strategy
/config/*.php
```

Do not scatter `getenv()` throughout modules.

---

### 3. Lightweight DI Container

Support:

```text
bind
singleton
instance
get
has
```

Prefer explicit registration.

Do not require reflection-heavy dependency injection.

---

### 4. HTTP Layer

Implement:

```text
Request
Response
JsonResponse
RedirectResponse if useful
```

Request must expose:

```text
method
path
host
query
input
header
cookie
```

---

### 5. Router

Support:

```text
GET
POST
PUT
PATCH
DELETE
HEAD
route parameters
named routes
middleware
```

Example:

```text
GET /api/v1/health
```

---

### 6. Middleware Pipeline

Provide middleware contract and pipeline.

Initial middleware:

```text
RequestIdMiddleware
ExceptionMiddleware
```

Provide extension points for:

```text
SiteResolutionMiddleware
SessionMiddleware
CsrfMiddleware
AuthenticationMiddleware
```

but do not implement Site/Auth domain behavior.

---

### 7. Database Layer

Use PDO.

Implement:

```text
ConnectionFactory
Connection abstraction if needed
TransactionManagerInterface
TransactionManager
```

Requirements:

```text
prepared statements
ERRMODE_EXCEPTION
safe fetch defaults
lazy connection if practical
```

Do NOT introduce ORM.

---

### 8. Migration System

Implement:

```text
MigrationInterface
migration discovery
migration locking
migration history
batch tracking
bin/migrate.php
```

Migration naming:

```text
YYYYMMDDHHMMSS_description.php
```

A migration must not execute twice.

---

### 9. Event System

Implement:

```text
DomainEventInterface
DomainEvent
EventDispatcherInterface
EventDispatcher
```

Features:

```text
listeners
priority
synchronous dispatch
critical/non-critical listener strategy
```

Non-critical listener failure should be logged.

---

### 10. Logging

Implement lightweight file logger.

Required methods:

```text
debug
info
warning
error
```

Log fields should support:

```text
timestamp
level
request_id
site_id
context
```

Never log secrets.

---

### 11. Filesystem

Implement:

```text
FilesystemInterface
Filesystem
LockInterface
FileLock
```

Atomic write:

```text
write temp
rename
```

Lock storage:

```text
/storage/locks
```

---

### 12. Shared Contract Placeholders

Create frozen interfaces:

```text
SiteContextInterface
ActorInterface
CacheInterface
ClockInterface
SessionInterface
CsrfTokenManagerInterface
```

Only implement generic/test implementations where appropriate.

Do NOT implement real SiteContext or authentication.

Those belong to later agents.

---

### 13. Testing Foundation

Configure PHPUnit.

Directories:

```text
tests/Unit
tests/Integration
tests/Contract
tests/Performance
tests/Security
```

Provide tests for:

```text
Config
Container
Router
middleware ordering
transaction commit
transaction rollback
event priority
migration once-only
file lock
atomic filesystem write
production exception response
```

---

### 14. Health Endpoint

Implement:

```text
GET /api/v1/health
```

Response:

```json
{
  "data": {
    "status": "ok"
  },
  "meta": {}
}
```

Must not expose system secrets.

---

### 15. Request ID

Every HTTP request receives a request ID.

Return:

```text
X-Request-ID
```

Logger must use it.

---

## Performance Requirements

Bootstrap must remain minimal.

Avoid eager initialization of:

```text
database
future plugins
future SEO
future media
admin features
```

Database connection SHOULD be lazy where feasible so future full-page cache hits can avoid connecting to MySQL entirely.

---

## Security Requirements

Production errors must never expose:

```text
SQL
passwords
stack traces
filesystem paths
environment secrets
```

Debug information only when:

```text
APP_DEBUG=true
```

---

## Forbidden Implementation

You MUST NOT:

```text
implement Content
implement Users
implement SEO
implement Themes
implement Plugin system
implement Media
implement Workflow

hardcode site_id
use raw request globals outside HTTP abstraction where avoidable
introduce an ORM
introduce a full framework
make Node.js a production requirement
introduce Redis
put feature logic into Core
```

---

## Required Architecture Tests

Add tests ensuring future forbidden dependencies can be detected.

At minimum establish architecture rules such as:

```text
Theme MUST NOT access Core\Database
HTTP Controllers MUST NOT import PDO directly
Block renderers MUST NOT access repositories
```

A lightweight source scan is acceptable initially.

---

## Definition of Done

Agent 01 is complete only when:

```text
application boots
/api/v1/health works
router tests pass
middleware tests pass
container tests pass
database connection works
transactions work
migration locking works
migration cannot run twice
events work
listener priority works
logging works
atomic file writes work
file locks work
request ID works
production error handling works
PHPUnit test suite passes
no product feature module has been implemented
```

---

## Required Completion Report

Return:

```text
IMPLEMENTATION_REPORT

Agent:
Agent 01 — Core Platform

Status:

Summary:

Files added:

Files modified:

Contracts implemented:

Database migrations:

Events implemented:

Tests:
- unit:
- integration:
- contract:
- security:
- performance:

Bootstrap performance notes:

Security notes:

Known limitations:

CONTRACT_CHANGE_REQUEST:
none / details

Ready for Agent 02 and Agent 03:
yes/no
```

If `Ready for Agent 02 and Agent 03 = no`, explain exactly which contract remains unstable.

Do not begin Agent 02 or Agent 03 work yourself.