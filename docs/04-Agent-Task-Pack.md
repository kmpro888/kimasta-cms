# Agent Task Pack

**Project:** Lightweight Portal CMS  
**Version:** 1.0  
**Purpose:** Implementation briefs untuk autonomous coding agents  
**Architecture:** Modular Monolith  
**Runtime:** PHP 8.x + MySQL/MariaDB + Apache  
**Production Node.js:** Tidak diperlukan

---

# 1. Global Rules for All Agents

Semua agent wajib mengikuti dokumen:

```text
PRD
Technical Architecture & Shared Contracts Specification
Database Schema & ERD Specification
REST API & Internal Service Contract Specification
```

Urutan precedence:

```text
Shared Contracts
      ↓
Database/API Contracts
      ↓
Agent Task
      ↓
Implementation
```

Jika terdapat konflik, agent tidak boleh mengubah contract secara diam-diam.

Agent harus melaporkan kebutuhan perubahan sebagai:

```text
CONTRACT_CHANGE_REQUEST
```

---

# 2. Global Development Rules

Semua agent MUST:

```text
PHP >= agreed project baseline
PSR-4
PSR-12
strict typing where practical
prepared statements
constructor dependency injection
SiteContext for tenant data
typed application exceptions
unit tests
integration tests
```

Semua agent MUST NOT:

```text
introduce Laravel
introduce mandatory Node.js runtime
introduce Redis dependency
introduce Elasticsearch dependency
query another module's tables directly
hardcode site_id = 1
modify core contracts silently
duplicate business logic
perform raw SQL from controllers/themes/blocks
```

---

# 3. Shared Repository Layout

```text
/app
  /Core
  /Modules
  /Shared

/admin
/api
/public
/plugins
/themes
/config
/database
/storage
/tests
/bin
/docs
```

---

# 4. Standard Agent Delivery Format

Setiap agent harus menghasilkan:

```text
Implementation
Database migration jika diperlukan
Unit tests
Integration tests
Public contract documentation
README module
Event documentation
Performance notes
Security notes
Merge checklist
```

---

# 5. Merge Report Required

Setiap agent selesai harus membuat report:

```text
Agent:
Module:

Files added:
Files modified:

Contracts consumed:
Contracts provided:

Database migrations:
Events emitted:
Events consumed:

API changes:
Cache impact:
Multisite impact:
Security impact:

Tests added:
Tests passed:

Known limitations:
Contract changes required:
```

---

# 6. Agent Dependency Graph

```text
                        Agent 01
                           Core
                            │
            ┌───────────────┴──────────────┐
            ↓                              ↓
       Agent 02                        Agent 03
       Multisite                       Identity
            │                              │
            └──────────────┬───────────────┘
                           ↓
                       Agent 04
                       Content
              ┌────────────┼─────────────┐
              ↓            ↓             ↓
          Agent 05      Agent 06      Agent 07
          Taxonomy       Media        Revision
              └────────────┼─────────────┘
                           ↓
                       Agent 08
                       Workflow
               ┌───────────┼─────────────┐
               ↓           ↓             ↓
           Agent 09     Agent 10      Agent 11
            Cache         SEO          Blocks
               │           │             │
               └──────┬────┴─────┬───────┘
                      ↓          ↓
                  Agent 12    Agent 13
                    Theme       API
                      │          │
                      └────┬─────┘
                           ↓
                       Agent 14
                   Features/Plugin
                           │
                           ↓
                       Agent 15
                    WP Migration
```

Beberapa agent dapat berjalan paralel setelah kontrak dependencynya tersedia.

---

# AGENT 01 — CORE PLATFORM

## Mission

Membangun foundation runtime ringan yang digunakan seluruh module.

## Ownership

```text
/app/Core
/app/Shared/Contracts
/database migration runner
/config bootstrap
/bin
```

## Owns

```text
Application bootstrap
HTTP kernel
Router
Middleware pipeline
Service container
Configuration
Database connection
Transaction manager
Migration runner
Event dispatcher
Filesystem abstraction
Logger
Exception handler
Request ID
```

## Must Provide

```php
ConfigInterface
EventDispatcherInterface
TransactionManagerInterface
LoggerInterface
```

Database primitives:

```text
Connection
Prepared query support
Transaction abstraction
```

## Forbidden

Agent tidak boleh implement:

```text
Posts
SEO
Users
Ads
Themes
Editorial workflow
```

## Primary Tasks

Implement minimal bootstrap.

Public request path harus memungkinkan:

```text
index.php
↓
config
↓
minimal container
↓
router
```

Implement container tanpa heavy reflection requirement.

Implement synchronous EventDispatcher.

Implement database abstraction berbasis PDO.

Implement migrations.

Implement application exception mapper foundation.

Implement production error handling.

Implement file-based logging.

## Acceptance Criteria

```text
Application boots without framework
Router works
Middleware works
PDO prepared statements work
Transaction rollback works
Events can be registered/dispatched
Migration can run only once
Production error hides stack trace
Tests can bootstrap application
```

## Tests

```text
Router matching
Middleware ordering
Transaction commit
Transaction rollback
Event priority
Migration locking
Config access
Production exception handling
```

---

# AGENT 02 — MULTISITE & SITE CONTEXT

## Mission

Menjamin seluruh aplikasi tenant-aware dan data antar-site tidak bocor.

## Depends On

```text
Agent 01
```

## Ownership

```text
/app/Modules/Site
```

Database:

```text
sites
site_domains
site_settings
```

## Must Provide

```php
SiteContextInterface
SiteResolverInterface
SiteServiceInterface
SettingsServiceInterface
```

## Primary Tasks

Implement:

```text
Site entity
Site repository
Domain repository
Host normalization
Domain resolver
SiteContext
Site settings
Compiled domain map
Autoload setting cache
Custom domain
Subdomain
```

## Request Flow

```text
Host header
↓
validate
↓
normalize
↓
filesystem domain map
↓ MISS
database
↓
SiteContext
```

## Domain Map

Generate:

```text
/storage/cache/system/domain-map.php
```

Agent must regenerate this whenever domain mapping changes.

## Security

Reject malformed Host headers.

Do not accept arbitrary `site_id` from standard tenant request.

## Acceptance Criteria

Site can resolve:

```text
example.com
news.example.com
customdomain.com
```

Site A request cannot resolve Site B data context.

Unknown domain produces:

```text
SITE_NOT_FOUND
```

No fallback to Site 1.

## Tests

```text
custom domain lookup
subdomain lookup
unknown domain
duplicate domain prevented
domain-map regeneration
settings cache
cross-site isolation
```

---

# AGENT 03 — IDENTITY, AUTH & RBAC

## Mission

Implement user identity, authentication, session, roles, dan capability authorization.

## Depends On

```text
Agent 01
Agent 02
```

## Ownership

```text
/app/Modules/Identity
```

Database:

```text
users
roles
capabilities
role_capabilities
user_roles
password_resets
```

## Must Provide

```php
ActorInterface
AuthenticationServiceInterface
AuthorizationServiceInterface
PasswordResetServiceInterface
UserServiceInterface
```

## Roles

Seed:

```text
administrator
editor
reviewer
author
contributor
subscriber
```

## Capabilities

Seed baseline from architecture specification.

Role logic must not be hardcoded throughout system.

## Authentication

Support:

```text
username + password
email + password
logout
forgot password
reset password
```

## Session Security

Implement:

```text
session regeneration after login
HTTP-only cookies
SameSite
Secure when HTTPS
logout invalidation
```

## Passwords

Use native PHP:

```php
password_hash()
password_verify()
```

## Acceptance Criteria

Contributor can:

```text
login
create own session
```

Contributor cannot automatically publish.

Editor capability can be assigned independently of role name.

Same email can exist on two different sites.

## Tests

```text
email login
username login
bad credentials
disabled account
password reset expiration
single-use reset
capability allow
capability deny
cross-site user isolation
session regeneration
```

---

# AGENT 04 — CONTENT ENGINE

## Mission

Membangun canonical publishing/content domain.

## Depends On

```text
Agent 01
Agent 02
Agent 03 contracts
```

## Ownership

```text
/app/Modules/Content
```

Database:

```text
contents
content_autosaves
url_registry integration for content
```

## Must Provide

```php
ContentRepositoryInterface
ContentServiceInterface
ContentQueryServiceInterface
ContentTypeRegistryInterface
SlugServiceInterface
UrlGeneratorInterface
```

## Core Content Types

Register:

```text
post
page
```

Custom types must work through registry.

## Storage

On save:

```text
content_json
content_html
content_plain
```

Block compilation itself belongs to Agent 11, so use compiler contract.

## Content Service

Support:

```text
create
update
get
trash
purge if explicitly supported
```

Publishing is controlled through workflow agent.

## Slug

Implement:

```text
normalization
collision detection
URL registry integration
```

## Query Optimization

Homepage/listing query should select only needed fields.

Must support batch relation loading contract.

## Events

Emit:

```text
content.created
content.updated
content.deleted
```

Workflow-specific events belong Agent 08.

## Acceptance Criteria

Create post.

Update post.

Custom content type works.

Duplicate URL rejected/resolved.

Site A cannot access Site B content.

No controller SQL.

## Performance

Uncached single content retrieval target:

```text
<= 5 logical queries
```

before all optional relations.

## Tests

```text
create
update
trash
slug generation
duplicate slug
URL collision
site isolation
content type registry
query pagination
query count regression
```

---

# AGENT 05 — TAXONOMY

## Mission

Implement category, tags dan custom taxonomies.

## Depends On

```text
Agent 01
Agent 02
Agent 04 contracts
```

## Ownership

```text
/app/Modules/Taxonomy
```

Database:

```text
taxonomies
terms
content_terms
```

## Must Provide

```php
TaxonomyRegistryInterface
TaxonomyServiceInterface
TermRepositoryInterface
```

## Default Taxonomies

```text
category
tag
```

Category:

```text
hierarchical = true
```

Tag:

```text
hierarchical = false
```

## Required Features

```text
register taxonomy
create term
update term
delete term
parent-child terms
attach content
detach content
sync content terms
content_count
```

## Events

Emit:

```text
taxonomy.created
taxonomy.updated
taxonomy.deleted

term.created
term.updated
term.deleted
```

## Performance

Content archive must avoid per-post term queries.

Provide batch loader.

## Acceptance Criteria

Custom taxonomy can be registered by plugin.

`content_count` remains accurate when attach/detach.

Deleting term does not delete content.

## Tests

```text
category hierarchy
tag
attach
detach
sync
count increment/decrement
duplicate slug
site isolation
batch query
```

---

# AGENT 06 — MEDIA ENGINE

## Mission

Implement efficient and secure media handling.

## Depends On

```text
Agent 01
Agent 02
Agent 03
```

## Ownership

```text
/app/Modules/Media
/public/uploads integration
```

Database:

```text
media
media_variants
```

## Must Provide

```php
MediaServiceInterface
MediaRepositoryInterface
ImageProcessorInterface
```

## Upload Pipeline

```text
receive upload
↓
validate PHP upload
↓
MIME inspection
↓
extension whitelist
↓
safe filename
↓
strip EXIF
↓
compress
↓
resize
↓
WebP
↓
persist records
```

## Image Engine

Preferred:

```text
Imagick
```

Fallback:

```text
GD
```

Runtime capability detection required.

## Storage

```text
/uploads/{site_id}/{year}/{month}/
```

Database stores relative path.

## Required Variants

Configurable defaults:

```text
thumbnail
medium
large
original
```

## Security

Uploads MUST NOT execute PHP.

Reject executable or suspicious double extensions.

## Events

Emit:

```text
media.created
media.updated
media.deleted
```

## Acceptance Criteria

JPEG/PNG upload works.

WebP generated when supported.

Fallback works without Imagick.

EXIF removed where supported.

Responsive dimensions available.

## Tests

```text
valid image
invalid executable
bad MIME
oversized upload
GD fallback
WebP capability
site path isolation
media update
referenced media deletion safeguards
```

---

# AGENT 07 — REVISION & AUTOSAVE

## Mission

Implement lightweight history without WordPress-style unlimited database growth.

## Depends On

```text
Agent 04
Agent 03
```

## Ownership

```text
/app/Modules/Revision
```

Database:

```text
content_revisions
content_autosaves
```

## Must Provide

```php
RevisionServiceInterface
AutosaveServiceInterface
```

## Revision Rules

Maximum:

```text
3 revisions/content
```

Before destructive content update:

```text
snapshot previous relevant state
```

When 4th revision created:

```text
remove oldest
```

## Autosave

One autosave per:

```text
content_id + user_id
```

Autosave MUST NOT create revision every time.

## Acceptance Criteria

After 10 updates only 3 persistent revisions remain.

Restore works.

Autosave does not alter published content.

## Tests

```text
revision creation
max 3
oldest deletion
restore
autosave update
autosave replacement
cross-site isolation
permission rules
```

---

# AGENT 08 — EDITORIAL WORKFLOW & SCHEDULER

## Mission

Implement editorial state machine.

## Depends On

```text
Agent 03
Agent 04
Agent 07
```

## Ownership

```text
/app/Modules/Editorial
```

Database:

```text
workflow_logs
```

## Must Provide

```php
EditorialWorkflowInterface
SchedulerServiceInterface
```

## State Machine

```text
draft
→ submitted
→ review
→ revision_requested
→ submitted

review
→ approved

approved
→ scheduled
→ published

published
→ archived
```

## Required Capabilities

Use authorization service.

Never directly inspect role name.

## Publish Transaction

Must coordinate:

```text
content status
revision
URL registry
workflow log
timestamps
```

Then commit.

After commit emit event.

## Events

```text
content.submitted
content.review_started
content.revision_requested
content.approved
content.scheduled
content.published
content.archived
```

## Scheduler

Provide:

```text
/bin/cron.php
```

or job integration contract.

Process scheduled content in batches.

Use lock.

## Acceptance Criteria

Contributor can submit but not publish without permission.

Reviewer can request revision.

Authorized publisher can publish.

Schedule triggers publishing.

Every transition has workflow log.

## Tests

```text
all legal transitions
illegal transitions
permission deny
exceptional admin transition
publish transaction
scheduled batch
scheduler locking
event emission
```

---

# AGENT 09 — CACHE & PERFORMANCE

## Mission

Make public frontend cache-first and database-light.

## Depends On

```text
Agent 01
Agent 02
Agent 04 event contracts
Agent 05 event contracts
Agent 08 event contracts
```

## Ownership

```text
/app/Modules/Cache
/storage/cache
```

## Must Provide

```php
CacheInterface
CacheInvalidationServiceInterface
PageCacheInterface
CacheDependencyInterface
```

## Required Cache Layers

```text
request cache
file object cache
full-page cache
generated artifact cache
```

## Full Page Cache Eligibility

Only:

```text
GET
HEAD
anonymous
public response
```

Never:

```text
admin
logged-in personalized page
preview
POST
draft
```

## Fast Path

```text
request
↓
minimal SiteResolver
↓
PageCache
↓ HIT
send HTML
exit
```

Goal:

```text
0 DB queries
```

on cached article after domain map is cached.

## Dependency Mapping

Example:

```text
page:/article
depends:
content:42
term:8
author:3
theme:x
site-settings
```

## Invalidation

Consume events:

```text
content.updated
content.published
content.deleted

term.updated
term.deleted

theme.activated
settings.updated
```

Invalidation must be targeted.

Do not wipe entire site cache for every article change.

## Locks

Filesystem locks to reduce cache stampede.

## Acceptance Criteria

Second anonymous request to article:

```text
0 DB queries
```

Article update immediately invalidates stale single page.

Related homepage/category archive invalidated.

Site A cache never used for Site B.

## Tests

```text
page MISS
page HIT
0-query HIT
targeted invalidation
site namespace
cache lock
expired cache
corrupt cache fallback
authenticated bypass
preview bypass
```

---

# AGENT 10 — SEO, SITEMAP & REDIRECTS

## Mission

Provide Yoast-style core SEO without plugin database overhead.

## Depends On

```text
Agent 01
Agent 02
Agent 04
Agent 05
Agent 06
Agent 09 events
```

## Ownership

```text
/app/Modules/Seo
```

Database:

```text
content_seo
redirects
```

## Must Provide

```php
SeoServiceInterface
SitemapServiceInterface
RedirectServiceInterface
BreadcrumbServiceInterface
```

## Features

```text
meta title
meta description
canonical
robots
Open Graph
Twitter/X metadata
schema.org
breadcrumb
XML sitemap
redirect 301/302
basic SEO scoring
```

## Schema Types

Initial:

```text
Article
NewsArticle
WebPage
Organization
Person
BreadcrumbList
WebSite
```

## Sitemap

Generated artifact.

Not dynamically rebuilt on every request.

Invalidate on content events.

## Redirect

Exact indexed lookup.

Avoid regex table scan.

## Acceptance Criteria

SEO tags centrally generated.

Theme cannot produce conflicting canonical logic.

Published content appears in sitemap.

Noindexed content handled correctly.

301/302 redirects resolve.

## Tests

```text
SEO defaults
custom SEO
canonical
OG
schema
sitemap generation
sitemap invalidation
redirect exact lookup
redirect loop rejection
cross-site redirects
Yoast mapping compatibility contract
```

---

# AGENT 11 — BLOCK EDITOR ENGINE

## Mission

Implement structured content system replacing Elementor-style runtime complexity.

## Depends On

```text
Agent 01
Agent 04 contracts
Agent 06 media contract
```

## Ownership

```text
/app/Modules/Block
Admin block editor components where applicable
```

## Must Provide

```php
BlockRegistryInterface
BlockCompilerInterface
BlockSanitizerInterface
BlockMigrationInterface
```

## Block Shape

Every block:

```json
{
  "type": "paragraph",
  "version": 1,
  "data": {}
}
```

## Core Blocks

```text
paragraph
heading
image
gallery
quote
list
table
button
embed
video
code
html
columns
section
hero
cta
faq
accordion
related_posts
ad_slot
```

## Compiler

```text
JSON
↓
validate
↓
sanitize
↓
resolve dynamic dependencies
↓
compile
↓
HTML
+
plain text
```

## Important Rule

Renderer MUST NOT query database.

Dynamic blocks use resolver/preparation stage.

## Versioning

Block schemas must support migration:

```text
v1 → v2
```

Old content must remain readable.

## Security

Raw HTML requires special capability or strict sanitizer.

## Acceptance Criteria

All core blocks compile deterministically.

Plugin can register block.

Old block version can migrate.

Compiled HTML can be saved to `contents`.

## Tests

```text
block validation
unknown block
HTML sanitization
version migration
compiler output
plain text output
plugin block
no DB access in renderer
```

---

# AGENT 12 — THEME & FRONTEND RENDERING

## Mission

Build theme system and public PHP rendering layer.

## Depends On

```text
Agent 02
Agent 04
Agent 05
Agent 06
Agent 09
Agent 10
Agent 11
```

## Ownership

```text
/app/Modules/Theme
/themes
public frontend rendering
```

## Must Provide

```php
ThemeRegistryInterface
TemplateResolverInterface
ViewRendererInterface
```

## Theme Structure

```text
/themes/theme-name/
  theme.json
  templates/
  partials/
  assets/
  functions.php
```

## Resolution

```text
single-{type}.php
single.php
index.php
```

Archive:

```text
archive-{type}.php
archive.php
index.php
```

Also:

```text
home.php
page.php
taxonomy.php
author.php
search.php
404.php
```

## Theme Data

Templates receive prepared ViewModels.

Theme MUST NOT query database.

## Frontend

Default:

```text
server-rendered PHP
+
minimal JS
+
page cache
```

## Events

Emit:

```text
theme.activated
```

## Acceptance Criteria

Theme switch works without touching content.

Custom post type can use special template.

SEO rendered centrally.

Responsive image helpers work.

Theme SQL access is unnecessary.

## Tests

```text
theme discovery
manifest validation
template hierarchy
fallback
theme activation
asset URL
multisite theme assignment
theme activation cache invalidation
```

---

# AGENT 13 — REST API

## Mission

Expose application capabilities through consistent `/api/v1`.

## Depends On

All relevant service contracts, especially:

```text
Identity
Content
Taxonomy
Media
Workflow
Revision
SEO
Search
Multisite
```

## Ownership

```text
/api
HTTP controllers/resources/validators under modules
```

## Must Implement

Authentication:

```text
/auth/login
/auth/logout
/auth/forgot-password
/auth/reset-password
/auth/me
```

Content:

```text
/content
/posts
/pages
workflow action endpoints
revisions
autosave
```

Also:

```text
taxonomies
media
SEO
redirects
comments
search
settings
plugins
themes
ads
notifications
sites
```

## API Rules

Controllers MUST NOT implement business logic.

Use explicit resources.

Never serialize domain entity automatically.

## Error Envelope

Must match shared contract exactly.

## Public Policy

Anonymous:

```text
read-only
```

Mutations:

```text
auth + capability
```

## Multisite

Site resolved by hostname.

Normal endpoints do not accept arbitrary site_id.

## Acceptance Criteria

API shapes match contract.

Cross-site IDs return 404.

No private user fields leak.

No N+1 from serializers.

## Tests

For every mutation:

```text
anonymous
unauthorized
authorized
other-site resource
validation error
success
```

Also contract snapshot tests for response shape.

---

# AGENT 14 — EXTENSIBILITY & PRODUCT FEATURES

This agent may be split into sub-agents if enough agent capacity exists.

---

## AGENT 14A — PLUGIN PLATFORM

### Ownership

```text
/app/Modules/Plugin
/plugins
```

Database:

```text
plugins
site_plugins
plugin_migrations
```

### Must Provide

```php
PluginManagerInterface
```

Features:

```text
discovery
manifest validation
activation
deactivation
install lifecycle
uninstall lifecycle
actions
filters
plugin migration
capability registration
routes
blocks
content types
taxonomies
```

### Isolation

Plugins must not edit core.

### Acceptance Criteria

Example plugin can register:

```text
content type
taxonomy
block
action
filter
API route
capability
```

without changing core.

---

## AGENT 14B — COMMENTS

### Ownership

```text
/app/Modules/Comment
```

Database:

```text
comments
contents.comment_count coordination
```

### Features

```text
create
reply
approve
spam
trash
open/close comments
```

Logged-in user required for submission.

### Events

```text
comment.created
comment.approved
comment.deleted
```

### Acceptance Criteria

Approved count stays correct.

Nested comments use bounded depth.

---

## AGENT 14C — FRONTEND SUBMISSION

### Ownership

Contributor-facing dashboard/controllers/UI.

Must reuse:

```text
ContentService
EditorialWorkflow
MediaService
```

Features:

```text
draft
edit own
media
submit
revision request
resubmit
status history
published list
```

No separate publishing logic.

---

## AGENT 14D — ADVERTISING

### Ownership

```text
/app/Modules/Advertising
```

Database:

```text
ads
ad_rules
```

Features:

```text
header
sidebar
before article
middle article
after article
sticky
footer
device
category
content type
date
```

Must provide:

```php
AdvertisingServiceInterface
```

Theme uses service by placement.

---

## AGENT 14E — NOTIFICATIONS

### Ownership

```text
/app/Modules/Notification
```

Database:

```text
notifications
notification_outbox
```

Consume:

```text
content.submitted
content.revision_requested
content.approved
content.scheduled
content.published
```

Support:

```text
internal
email
```

Each event/channel configurable ON/OFF.

No permanent queue worker required.

Cron processes email outbox.

---

## AGENT 14F — SEARCH

### Ownership

```text
/app/Modules/Search
```

Provide:

```php
SearchServiceInterface
```

Primary:

```text
MySQL/MariaDB FULLTEXT
```

Fallback:

lightweight indexed strategy.

No Elasticsearch dependency.

---

## AGENT 14G — ANALYTICS INTEGRATION

No local page-view analytics.

Support:

```text
Google Analytics
Matomo
```

Provide centrally rendered integration snippets.

No DB write on visitor page view.

---

# AGENT 15 — WORDPRESS MIGRATION

## Mission

Safely migrate existing WordPress portal into new CMS.

## Depends On

All primary domain services.

This agent should start after target service contracts stabilize.

## Ownership

```text
/app/Modules/Migration
```

Database:

```text
migration_maps
migration_logs
```

## Source Support

Read:

```text
wp_posts
wp_postmeta
wp_users
wp_terms
wp_term_taxonomy
wp_term_relationships
wp_comments
```

## Import Targets

```text
posts
pages
users
authors
categories
tags
featured images
media
comments
slug
publish date
custom fields
Yoast SEO
```

## Mapping

Examples:

```text
_wpseo_title
→ content_seo.meta_title

_wpseo_metadesc
→ content_seo.meta_description

_wpseo_canonical
→ content_seo.canonical_url

_thumbnail_id
→ featured_media_id
```

## Import Process

```text
analyze
↓
plan
↓
batch import
↓
mapping
↓
validation
↓
report
```

Must support batch processing to avoid shared-host timeout.

## Idempotency

Store source→target mappings.

Rerunning a completed batch must not duplicate content.

## URL Preservation

If old URL can remain:

```text
preserve it
```

If not:

```text
create 301 redirect
```

## Unknown Metadata

Do not silently discard.

Log:

```text
source ID
field
reason
```

Optionally preserve selected unknown metadata in `entity_meta`.

## Acceptance Criteria

Sample WordPress site migrates:

```text
post
page
author
category
tag
media
featured image
comment
Yoast SEO
slug
publish date
```

URL integrity report generated.

## Tests

```text
single post
page
taxonomy
user
media
comments
Yoast fields
unknown postmeta
retry same batch
interrupted migration resume
URL redirect
multisite target isolation
```

---

# 7. Integration Agent / Final Hardening

Setelah agents selesai, satu integration pass diperlukan.

Ini dapat dilakukan oleh dedicated integration agent.

## Mission

Tidak menambah feature baru.

Hanya:

```text
merge modules
run migrations
contract tests
performance tests
security tests
integration fixes
```

## Mandatory End-to-End Test

Scenario:

```text
Contributor login
↓
create article
↓
upload image
↓
add category
↓
set SEO
↓
submit
↓
Reviewer requests revision
↓
Contributor edits
↓
resubmit
↓
Reviewer approves
↓
Editor schedules/publishes
↓
page cache generated
↓
visitor reads article
↓
second visitor causes 0 DB queries
↓
editor updates title
↓
cache invalidated
↓
new title visible immediately
```

---

# 8. Multisite E2E Test

Create:

```text
site-a.com
site-b.com
```

Create same slug:

```text
/news
```

on both sites.

Expected:

```text
isolated content
isolated users
isolated theme
isolated plugins
isolated cache
isolated SEO
isolated media
```

No data leakage.

---

# 9. Performance Gate

Seed approximately:

```text
50,000 contents
100,000 content-term relations
100,000 comments
```

Run:

```text
homepage
single article
category archive
tag archive
author archive
search
API list
scheduled publication
redirect lookup
```

High-frequency queries must be reviewed using:

```sql
EXPLAIN
```

---

# 10. Production Performance Acceptance

Cached article:

```text
0 DB queries
```

Uncached article target:

```text
<= 5 logical queries
```

Archive target:

```text
<= 5 logical queries
```

Public request should not load admin system.

Public cache-hit request should not bootstrap all plugins unnecessarily.

---

# 11. Security Gate

Must verify:

```text
SQL injection resistance
XSS escaping
raw HTML sanitation
CSRF
session security
password hashing
reset token security
upload security
directory execution protection
RBAC
site isolation
API private data exposure
```

---

# 12. Shared Hosting Gate

Production package must run with:

```text
PHP
MySQL/MariaDB
Apache
filesystem
```

Installation/deployment must not require:

```text
npm install
Node server
Redis
Docker
Supervisor
root access
```

Composer can be used in development, but release package includes dependencies.

---

# 13. Agent Parallelization Strategy

### Wave 1

Start immediately:

```text
Agent 01 Core
```

### Wave 2

After core interfaces:

```text
Agent 02 Multisite
Agent 03 Identity
```

### Wave 3

After SiteContext and Actor contracts stabilize:

```text
Agent 04 Content
Agent 06 Media foundation
```

### Wave 4

After Content contracts:

```text
Agent 05 Taxonomy
Agent 07 Revision
Agent 11 Block Engine
```

### Wave 5

After Content + Identity:

```text
Agent 08 Workflow
```

### Wave 6

After domain events stabilize:

```text
Agent 09 Cache
Agent 10 SEO
Agent 12 Theme
```

### Wave 7

After service contracts stabilize:

```text
Agent 13 API
Agent 14 feature agents
```

### Wave 8

Final feature:

```text
Agent 15 WordPress Migration
```

### Wave 9

```text
Integration / Hardening Agent
```

---

# 14. Most Important Merge Order

Even if code is written in parallel, recommended merge order:

```text
Core
↓
Multisite
↓
Identity
↓
Content
↓
Taxonomy / Media / Revision / Blocks
↓
Workflow
↓
Cache
↓
SEO
↓
Theme
↓
API
↓
Plugins + Features
↓
Migration
↓
Integration
```

Cache should be merged relatively early because performance is a primary product requirement, not a post-launch optimization.

---

# 15. Agent Prompt Header Template

Setiap task dapat diberikan ke coding agent dengan header berikut:

```text
You are Agent XX for the Lightweight Portal CMS project.

Your exclusive module ownership is:
[MODULE]

Before making any implementation decision, follow these documents in order:

1. Technical Architecture & Shared Contracts Specification
2. Database Schema & ERD Specification
3. REST API & Internal Service Contract Specification
4. This Agent Task

You MUST NOT redesign global architecture.

If a required change conflicts with a frozen contract, do not silently implement it.
Return a CONTRACT_CHANGE_REQUEST describing:

- existing contract
- problem
- proposed change
- affected modules
- migration impact
- backward compatibility impact

Implement only the domain assigned to you.

You MUST:
- preserve multisite isolation
- use SiteContext
- use prepared queries
- respect module boundaries
- emit documented events
- consume documented interfaces
- add tests
- report query/performance impact
- report security impact

You MUST NOT:
- introduce a large framework
- require Node.js in production
- require Redis
- directly query another module's tables
- hardcode site IDs
- duplicate domain logic
- weaken authorization for convenience
```

---

# 16. Task Completion Prompt

Saat agent selesai, agent harus mengembalikan:

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

Tests:
- unit
- integration
- security
- performance

Multisite validation:

Query/performance impact:

Security considerations:

Known limitations:

CONTRACT_CHANGE_REQUEST:
none / details

Ready to merge:
yes/no
```

---

# 17. Integration Conflict Rule

Jika Agent A dan Agent B membutuhkan perubahan pada shared contract yang sama:

```text
DO NOT MERGE TWO DIFFERENT CONTRACTS
```

Process:

```text
raise contract change
↓
Architecture decision
↓
update contract
↓
both agents rebase
↓
continue
```

---

# 18. Feature Freeze Rule

Selama foundation implementation, agent dilarang menambah fitur yang tidak ada di PRD hanya karena:

```text
easy to add
common CMS feature
WordPress has it
library supports it
```

Feature baru harus melalui product backlog.

Hal ini penting agar CMS tidak kembali menjadi bloated seperti sistem yang ingin digantikan.

---

# 19. Architecture North Star for Every Agent

Setiap agent harus mempertanyakan:

```text
Apakah perubahan ini menambah query public request?

Apakah perubahan ini memaksa bootstrap tambahan?

Apakah data ini seharusnya menjadi first-class column/table?

Apakah fitur ini dapat di-cache?

Apakah perubahan content dapat menginvalidasi cache secara targeted?

Apakah module ini tetap bekerja tanpa Redis/Node daemon?

Apakah Site A benar-benar terisolasi dari Site B?

Apakah module lain harus tahu concrete implementation ini?
```

Jika jawabannya mengarah ke tighter coupling atau lebih banyak query, desain harus ditinjau ulang.

---

# 20. Project Completion Gate

CMS dianggap production candidate setelah semua kondisi berikut terpenuhi:

```text
Core contracts implemented

Multisite isolation passes

Authentication/RBAC passes

Publishing workflow passes

Revision maximum 3 works

Media optimization works

SEO output works

Sitemap works

Redirect manager works

Block compiler works

Theme system works

Plugin sample works

Frontend submission works

Comments work

Ad placements work

Notifications can be disabled

Search works without Elasticsearch

Public API is read-only for anonymous users

Write API enforces auth/capabilities

WordPress migration works

Yoast metadata migration works

Cached article uses 0 DB queries

Article update invalidates cache immediately

Shared hosting deployment works without Node runtime
```

---

# 21. Recommended First Implementation Sprint

Do not start 15 agents simultaneously on day one.

First establish:

```text
Agent 01 Core
+
Agent 02 SiteContext contract
+
Agent 03 Actor contract
```

Then freeze:

```text
SiteContextInterface
ActorInterface
EventDispatcherInterface
TransactionManagerInterface
ConfigInterface
```

After this, start Content.

After Content entity/service contract passes initial tests, parallel development can expand aggressively.

---

# 22. Recommended First Technical Milestone

The first useful vertical slice should be:

```text
Request
↓
resolve site
↓
route
↓
login
↓
create post
↓
store database
↓
publish
↓
render theme
↓
cache HTML
↓
second request = 0 DB queries
```

Do this before building every advanced CMS screen.

If this vertical slice works correctly, the fundamental architecture is validated.

---

# 23. Definition of Agent-Friendly Architecture

The project has succeeded architecturally when:

Agent SEO can change SEO without editing Content internals.

Agent Cache can change cache implementation without changing Content.

Agent API can expose content without rewriting Content logic.

Agent Theme can render content without querying database.

Agent Plugin can add features without modifying Core.

Agent Migration can import WordPress using application services.

Agent Multisite can guarantee tenant isolation without every agent manually remembering `WHERE site_id = ?`.

That level of separation is the primary engineering objective of this task pack.