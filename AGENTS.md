# AGENTS.md

## Project

Lightweight Portal CMS

A lightweight, cache-first, database-efficient publishing CMS designed for standard shared hosting.

Production target:

- PHP 8.x
- MySQL / MariaDB
- Apache
- Filesystem
- Cron when available

Production MUST NOT require:

- Node.js runtime
- Redis
- Memcached
- Elasticsearch
- Docker
- Supervisor
- RabbitMQ
- Kafka
- long-running workers

---

## Required Reading

Before modifying code, read the relevant project documents.

Source-of-truth priority:

1. `docs/01-PRD.md`
2. `docs/02-Technical-Architecture-Shared-Contracts.md`
3. `docs/03-Database-Schema-ERD.md`
4. `docs/04-REST-API-Internal-Service-Contracts.md`
5. `docs/05-Agent-Task-Pack.md`
6. `docs/06-Repository-Bootstrap-Specification.md`
7. Your assigned file under `docs/agents/`

Higher-priority documents override lower-priority documents.

---

## Architecture

This project uses a:

**Modular Monolith**

Primary dependency direction:

```text
HTTP / UI
    ↓
Application Service
    ↓
Domain
    ↓
Repository Interface
    ↓
Infrastructure Repository
    ↓
Database
```

Do not redesign the application into microservices.

---

## Core Performance Principle

Public content should require as little PHP and database work as possible.

Target cached request:

```text
Request
  ↓
Site/Domain Cache
  ↓
Full Page Cache
  ↓
HTML
```

Target:

```text
0 database queries
```

for cached anonymous public pages.

---

## Multisite

All tenant-owned data must respect `SiteContext`.

Never hardcode:

```text
site_id = 1
```

Never trust arbitrary `site_id` supplied by normal tenant requests.

Site A must never be able to read or mutate Site B data.

Cross-site resource access should normally behave as:

```text
404
```

rather than revealing that the resource exists.

---

## Database Rules

Do not use WordPress-style generic metadata storage for core fields.

Prefer explicit tables and columns for:

- content
- SEO
- media
- taxonomy
- workflow
- users

Avoid:

- N+1 queries
- `SELECT *` on high-traffic queries
- database writes for visitor page views
- unindexed high-frequency lookups

Prepared statements are mandatory.

---

## Cache Rules

Filesystem caching is the default production cache.

Do not introduce Redis as a requirement.

Content changes must use targeted event-driven invalidation.

Do not clear the entire site cache after every article edit unless explicitly used as a recovery fallback.

Successful content updates must become publicly visible immediately.

---

## Module Boundaries

Only modify files owned by your assigned agent unless the task explicitly requires a coordinated cross-module change.

Communicate across modules through:

- interfaces
- application services
- events
- DTOs
- registries

Do not directly query another module's tables.

---

## Forbidden Dependencies

The following are prohibited unless architecture contracts are explicitly changed:

```text
Controller → raw SQL
Theme → Database
Theme → Repository
Block Renderer → Database
Block Renderer → Repository
Plugin → undocumented core table
Feature module → arbitrary global site ID
```

---

## Frozen Contracts

Do not silently modify frozen contracts, including:

- `SiteContextInterface`
- `ActorInterface`
- `ContentServiceInterface`
- `ContentRepositoryInterface`
- `CacheInterface`
- `EventDispatcherInterface`
- REST success envelope
- REST error envelope
- block base schema
- plugin manifest
- theme manifest
- event naming convention

If a frozen contract must change, return a:

```text
CONTRACT_CHANGE_REQUEST
```

containing:

```text
Existing contract:
Problem:
Proposed change:
Reason:
Affected modules:
Database impact:
API impact:
Backward compatibility impact:
Migration required:
Alternative considered:
```

Do not create a competing interface or workaround.

---

## Security

Every mutation must consider:

- authentication
- authorization
- validation
- CSRF where appropriate
- multisite isolation

Passwords must use PHP native password hashing.

Uploads must be MIME validated.

Uploaded files must not be executable as PHP.

Production errors must not expose:

- SQL
- passwords
- secrets
- environment variables
- filesystem paths
- stack traces

---

## Testing

Every implementation must add appropriate tests.

Depending on the module:

- unit tests
- integration tests
- contract tests
- security tests
- multisite isolation tests
- query/performance regression tests

Code is not complete merely because the happy path works manually.

---

## Agent Workflow

Before coding:

1. Read this file.
2. Read the required architecture documents.
3. Read your assigned `docs/agents/Agent-XX-*.md`.
4. Identify owned files.
5. Identify consumed contracts.
6. Identify provided contracts.
7. Identify database ownership.
8. Identify emitted/consumed events.
9. Identify required tests.
10. Implement only the assigned module.

---

## No Scope Expansion

Do not add functionality simply because:

- WordPress has it;
- a library makes it easy;
- it may be useful later.

Unrequested features belong in the backlog.

The project intentionally prioritizes a small, predictable core.

---

## Completion Report

At the end of your task, provide:

```text
IMPLEMENTATION_REPORT

Agent:
Status:

Summary:

Files added:
Files modified:

Contracts implemented:
Contracts consumed:

Database migrations:

Events emitted:
Events consumed:

API changes:

Cache impact:
Multisite impact:
Security impact:

Tests added:
- unit:
- integration:
- contract:
- security:
- performance:

Tests passed:

Query/performance notes:

Known limitations:

CONTRACT_CHANGE_REQUEST:
none / details

Ready to merge:
yes/no
```

---

## Important

Do not begin work belonging to another agent.

Do not redesign global architecture to simplify your local task.

Preserve compatibility with standard shared hosting.

When uncertain, prefer:

- smaller dependencies;
- fewer database queries;
- explicit contracts;
- deterministic behavior;
- targeted cache invalidation;
- strict multisite isolation.