# REST API & Internal Service Contract Specification

**Project:** Lightweight Portal CMS  
**Version:** 1.0  
**Status:** Contract Baseline  
**API Base:** `/api/v1`  
**Architecture:** Controller → Application Service → Repository  
**Target Runtime:** PHP 8.x + MySQL/MariaDB  
**Primary Consumers:** Admin UI, frontend submission, public frontend, plugins, migration tools

---

# 1. Purpose

Dokumen ini mengunci kontrak komunikasi aplikasi.

Tujuan utamanya adalah memastikan semua agent menggunakan business logic yang sama.

Tidak boleh ada implementasi terpisah seperti:

```text
Admin publish logic
Frontend publish logic
API publish logic
Migration publish logic
```

Semua harus berakhir pada service yang sama.

Contoh:

```text
Admin
Frontend Submission
REST API
Migration
Plugin
      │
      ▼
ContentService
      │
      ▼
Repository
```

---

# 2. API Principles

API MUST:

- versioned;
- predictable;
- site-aware;
- permission-aware;
- pagination-aware;
- consistent error format;
- tidak membawa business logic utama di controller;
- tidak mengakses database langsung.

---

# 3. Base Path

```text
/api/v1
```

Semua public API berada di bawah versi.

Contoh:

```text
/api/v1/posts
/api/v1/pages
/api/v1/categories
/api/v1/media
```

---

# 4. Public vs Protected API

Public anonymous API:

```text
GET
HEAD
```

hanya untuk data public.

Mutation:

```text
POST
PATCH
PUT
DELETE
```

wajib:

```text
authenticated
+
authorized
```

---

# 5. Request Flow

```text
HTTP Request
      ↓
Router
      ↓
Middleware
      ↓
Controller
      ↓
Request Validator
      ↓
DTO
      ↓
Application Service
      ↓
Repository
      ↓
Domain Event
      ↓
Response Resource
```

---

# 6. Controller Rule

Controller MAY:

- membaca request;
- melakukan validation adapter;
- memanggil service;
- memilih HTTP status;
- membentuk resource response.

Controller MUST NOT:

- menjalankan SQL;
- menentukan workflow transition sendiri;
- invalidate cache secara manual;
- mengirim email langsung;
- generate sitemap;
- mengelola plugin hook secara manual.

---

# 7. Standard Success Envelope

Single resource:

```json
{
  "data": {
    "id": 100
  },
  "meta": {}
}
```

Collection:

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 100,
    "total_pages": 5
  }
}
```

---

# 8. Standard Error Envelope

```json
{
  "error": {
    "code": "CONTENT_NOT_FOUND",
    "message": "Content not found.",
    "details": {}
  }
}
```

---

# 9. Standard HTTP Status

```text
200 OK
201 CREATED
204 NO CONTENT

400 BAD REQUEST
401 UNAUTHENTICATED
403 FORBIDDEN
404 NOT FOUND
409 CONFLICT
422 UNPROCESSABLE ENTITY
429 TOO MANY REQUESTS
500 INTERNAL SERVER ERROR
```

---

# 10. Core Error Codes

```text
VALIDATION_ERROR
UNAUTHENTICATED
FORBIDDEN
RESOURCE_NOT_FOUND
CONTENT_NOT_FOUND
USER_NOT_FOUND
MEDIA_NOT_FOUND
TERM_NOT_FOUND
SITE_NOT_FOUND

SLUG_ALREADY_EXISTS
URL_ALREADY_EXISTS
EMAIL_ALREADY_EXISTS
USERNAME_ALREADY_EXISTS

INVALID_STATE_TRANSITION
CONTENT_NOT_PUBLISHABLE
CONTENT_NOT_EDITABLE

INVALID_MEDIA_TYPE
MEDIA_TOO_LARGE

INVALID_CREDENTIALS
INVALID_RESET_TOKEN
RESET_TOKEN_EXPIRED

PLUGIN_NOT_FOUND
PLUGIN_INCOMPATIBLE

THEME_NOT_FOUND

RATE_LIMITED

INTERNAL_ERROR
```

---

# 11. Validation Error Example

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The submitted data is invalid.",
    "details": {
      "title": [
        "Title is required."
      ],
      "slug": [
        "Slug already exists."
      ]
    }
  }
}
```

---

# 12. Authentication Endpoints

```text
POST /api/v1/auth/login
POST /api/v1/auth/logout
POST /api/v1/auth/forgot-password
POST /api/v1/auth/reset-password
GET  /api/v1/auth/me
```

---

# 13. Login

```text
POST /api/v1/auth/login
```

Request:

```json
{
  "identity": "user@example.com",
  "password": "secret"
}
```

`identity` dapat berupa:

```text
email
username
```

Success:

```json
{
  "data": {
    "user": {
      "id": 15,
      "username": "author1",
      "display_name": "Author One"
    }
  },
  "meta": {}
}
```

Session dibuat oleh authentication service.

---

# 14. Authentication Service Contract

```php
interface AuthenticationServiceInterface
{
    public function attempt(
        string $identity,
        string $password
    ): AuthenticatedUser;

    public function logout(
        ActorInterface $actor
    ): void;

    public function currentUser(): ?AuthenticatedUser;
}
```

---

# 15. Password Reset Service

```php
interface PasswordResetServiceInterface
{
    public function requestReset(
        string $email
    ): void;

    public function reset(
        string $token,
        string $newPassword
    ): void;
}
```

API MUST NOT reveal whether an email exists.

Response forgot password:

```text
If the account exists, reset instructions will be sent.
```

---

# 16. User Endpoints

Protected:

```text
GET    /api/v1/users
POST   /api/v1/users
GET    /api/v1/users/{id}
PATCH  /api/v1/users/{id}
DELETE /api/v1/users/{id}
```

Public author profile MAY use separate endpoint:

```text
GET /api/v1/authors/{id}
```

---

# 17. Public Author Resource

Must not expose:

```text
email
username jika dianggap private
roles internal
capabilities
last login
password hash
```

Example:

```json
{
  "data": {
    "id": 15,
    "display_name": "John Doe",
    "bio": "Technology writer."
  },
  "meta": {}
}
```

---

# 18. User Service Contract

```php
interface UserServiceInterface
{
    public function create(
        CreateUserData $data,
        ActorInterface $actor
    ): User;

    public function update(
        int $id,
        UpdateUserData $data,
        ActorInterface $actor
    ): User;

    public function disable(
        int $id,
        ActorInterface $actor
    ): void;
}
```

---

# 19. Content Endpoints

Generic internal API:

```text
GET    /api/v1/content
POST   /api/v1/content
GET    /api/v1/content/{id}
PATCH  /api/v1/content/{id}
DELETE /api/v1/content/{id}
```

Convenience public aliases MAY exist:

```text
GET /api/v1/posts
GET /api/v1/pages
```

---

# 20. Public Content Collection

```text
GET /api/v1/posts
```

Parameters:

```text
page
per_page
category
tag
author
search
order
orderby
```

Public automatically applies:

```text
status = published
published_at <= now
```

Client MUST NOT override public status filter.

---

# 21. Public Content Response

```json
{
  "data": [
    {
      "id": 182,
      "type": "post",
      "title": "Judul Artikel",
      "slug": "judul-artikel",
      "excerpt": "Ringkasan...",
      "url": "https://example.com/judul-artikel",
      "published_at": "2026-10-24T03:00:00Z",
      "author": {
        "id": 10,
        "display_name": "Editor"
      },
      "featured_media": {
        "id": 551,
        "url": "https://example.com/uploads/..."
      }
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 200,
    "total_pages": 10
  }
}
```

---

# 22. Content Detail

```text
GET /api/v1/content/{id}
```

Authenticated admin may receive extended fields.

Public response may include:

```text
id
type
title
slug
excerpt
compiled_html
published_at
author
terms
featured_media
seo
url
```

Do not expose raw internal workflow information publicly.

---

# 23. Create Content Request

```json
{
  "type": "post",
  "title": "Judul Artikel",
  "slug": "judul-artikel",
  "excerpt": "Ringkasan",
  "blocks": [
    {
      "type": "paragraph",
      "version": 1,
      "data": {
        "text": "Isi artikel"
      }
    }
  ],
  "featured_media_id": 12,
  "term_ids": [4, 8]
}
```

---

# 24. Create Content DTO

```php
final class CreateContentData
{
    public string $type;
    public string $title;
    public ?string $slug;
    public ?string $excerpt;
    public array $blocks;
    public ?int $featuredMediaId;
    public array $termIds;
}
```

---

# 25. Update Content DTO

```php
final class UpdateContentData
{
    public ?string $title;
    public ?string $slug;
    public ?string $excerpt;
    public ?array $blocks;
    public ?int $featuredMediaId;
    public ?array $termIds;
}
```

Partial update uses:

```text
PATCH
```

---

# 26. Content Service Contract

```php
interface ContentServiceInterface
{
    public function create(
        CreateContentData $data,
        ActorInterface $actor
    ): Content;

    public function update(
        int $id,
        UpdateContentData $data,
        ActorInterface $actor
    ): Content;

    public function get(
        int $id,
        ActorInterface $actor
    ): Content;

    public function delete(
        int $id,
        ActorInterface $actor
    ): void;
}
```

Publish tidak menjadi method `update(status)` generik.

Workflow harus eksplisit.

---

# 27. Workflow Endpoints

```text
POST /api/v1/content/{id}/submit
POST /api/v1/content/{id}/start-review
POST /api/v1/content/{id}/request-revision
POST /api/v1/content/{id}/approve
POST /api/v1/content/{id}/schedule
POST /api/v1/content/{id}/publish
POST /api/v1/content/{id}/archive
```

Tujuannya agar transition semantik jelas.

---

# 28. Submit

```text
POST /api/v1/content/182/submit
```

Response:

```json
{
  "data": {
    "id": 182,
    "status": "submitted"
  },
  "meta": {}
}
```

Required capability:

```text
content.submit
```

atau owner-specific policy.

---

# 29. Request Revision

```text
POST /api/v1/content/{id}/request-revision
```

Request:

```json
{
  "note": "Tambahkan sumber pada paragraf ketiga."
}
```

Required capability:

```text
content.review
```

---

# 30. Schedule

```text
POST /api/v1/content/{id}/schedule
```

Request:

```json
{
  "scheduled_at": "2026-10-25T03:00:00Z"
}
```

Required:

```text
content.schedule
```

---

# 31. Publish

```text
POST /api/v1/content/{id}/publish
```

Required:

```text
content.publish
```

Side effects:

```text
content status updated
published_at set
URL registry updated
workflow log stored
content.published emitted
cache invalidated
sitemap invalidated
notification triggered if enabled
plugin hooks invoked
```

Controller tidak mengerjakan side effect tersebut sendiri.

---

# 32. Editorial Service Contract

```php
interface EditorialWorkflowInterface
{
    public function submit(
        int $contentId,
        ActorInterface $actor
    ): Content;

    public function startReview(
        int $contentId,
        ActorInterface $actor
    ): Content;

    public function requestRevision(
        int $contentId,
        string $note,
        ActorInterface $actor
    ): Content;

    public function approve(
        int $contentId,
        ActorInterface $actor
    ): Content;

    public function schedule(
        int $contentId,
        DateTimeImmutable $at,
        ActorInterface $actor
    ): Content;

    public function publish(
        int $contentId,
        ActorInterface $actor
    ): Content;

    public function archive(
        int $contentId,
        ActorInterface $actor
    ): Content;
}
```

---

# 33. Revisions API

```text
GET  /api/v1/content/{id}/revisions
GET  /api/v1/content/{id}/revisions/{revisionId}
POST /api/v1/content/{id}/revisions/{revisionId}/restore
```

Maximum persistent revisions:

```text
3
```

---

# 34. Revision Service

```php
interface RevisionServiceInterface
{
    public function list(
        int $contentId,
        ActorInterface $actor
    ): array;

    public function restore(
        int $contentId,
        int $revisionId,
        ActorInterface $actor
    ): Content;
}
```

Restore MUST create appropriate new revision/history before destructive replacement where applicable.

---

# 35. Autosave API

```text
PUT    /api/v1/content/{id}/autosave
GET    /api/v1/content/{id}/autosave
DELETE /api/v1/content/{id}/autosave
```

Autosave does not emit normal `content.updated`.

It MAY emit:

```text
content.autosaved
```

if needed.

---

# 36. Taxonomy Endpoints

```text
GET    /api/v1/taxonomies
GET    /api/v1/taxonomies/{taxonomy}/terms
POST   /api/v1/taxonomies/{taxonomy}/terms
GET    /api/v1/terms/{id}
PATCH  /api/v1/terms/{id}
DELETE /api/v1/terms/{id}
```

---

# 37. Term Resource

```json
{
  "id": 4,
  "taxonomy": "category",
  "name": "Teknologi",
  "slug": "teknologi",
  "description": "",
  "parent_id": null,
  "content_count": 182,
  "url": "https://example.com/category/teknologi"
}
```

---

# 38. Taxonomy Service Contract

```php
interface TaxonomyServiceInterface
{
    public function createTerm(
        string $taxonomy,
        CreateTermData $data,
        ActorInterface $actor
    ): Term;

    public function updateTerm(
        int $termId,
        UpdateTermData $data,
        ActorInterface $actor
    ): Term;

    public function deleteTerm(
        int $termId,
        ActorInterface $actor
    ): void;

    public function syncContentTerms(
        int $contentId,
        array $termIds
    ): void;
}
```

---

# 39. Media API

```text
GET    /api/v1/media
POST   /api/v1/media
GET    /api/v1/media/{id}
PATCH  /api/v1/media/{id}
DELETE /api/v1/media/{id}
```

Upload uses:

```text
multipart/form-data
```

---

# 40. Media Upload Response

```json
{
  "data": {
    "id": 501,
    "mime_type": "image/jpeg",
    "width": 1600,
    "height": 900,
    "url": "https://example.com/uploads/...",
    "variants": {
      "thumbnail": {
        "url": "...",
        "width": 320,
        "height": 180
      },
      "medium": {
        "url": "...",
        "width": 768,
        "height": 432
      }
    }
  },
  "meta": {}
}
```

---

# 41. Media Service Contract

```php
interface MediaServiceInterface
{
    public function upload(
        UploadedFile $file,
        UploadMediaData $data,
        ActorInterface $actor
    ): Media;

    public function update(
        int $id,
        UpdateMediaData $data,
        ActorInterface $actor
    ): Media;

    public function delete(
        int $id,
        ActorInterface $actor
    ): void;
}
```

Media service handles:

```text
validation
EXIF removal
compression
resize
WebP generation
metadata persistence
```

---

# 42. SEO API

```text
GET /api/v1/content/{id}/seo
PUT /api/v1/content/{id}/seo
```

Request:

```json
{
  "meta_title": "SEO Title",
  "meta_description": "Description",
  "canonical_url": null,
  "robots_index": true,
  "robots_follow": true,
  "og_title": null,
  "og_description": null,
  "og_image_id": 500,
  "schema_type": "NewsArticle"
}
```

---

# 43. SEO Permission

Default:

```text
seo.manage
```

Editors MAY receive permission.

Contributor MAY be allowed to edit SEO through configurable capability mapping later.

---

# 44. Redirect API

```text
GET    /api/v1/redirects
POST   /api/v1/redirects
PATCH  /api/v1/redirects/{id}
DELETE /api/v1/redirects/{id}
```

Required:

```text
redirect.manage
```

---

# 45. Redirect Request

```json
{
  "source_path": "/old-url",
  "target_url": "/new-url",
  "status_code": 301
}
```

Validation:

```text
source path valid
target valid
status only 301/302
no direct infinite loop
```

---

# 46. Comments Public API

```text
GET  /api/v1/content/{id}/comments
POST /api/v1/content/{id}/comments
```

User must be logged in according to product requirement.

Comment create:

```json
{
  "body": "Komentar...",
  "parent_id": null
}
```

---

# 47. Comment Moderation API

```text
POST /api/v1/comments/{id}/approve
POST /api/v1/comments/{id}/spam
POST /api/v1/comments/{id}/trash
```

Required:

```text
comment.moderate
```

---

# 48. Comment Service Contract

```php
interface CommentServiceInterface
{
    public function create(
        int $contentId,
        CreateCommentData $data,
        ActorInterface $actor
    ): Comment;

    public function approve(
        int $commentId,
        ActorInterface $actor
    ): Comment;

    public function markSpam(
        int $commentId,
        ActorInterface $actor
    ): Comment;

    public function trash(
        int $commentId,
        ActorInterface $actor
    ): Comment;
}
```

---

# 49. Frontend Submission API

Frontend contributor dashboard MUST use regular content services.

Convenience endpoints MAY exist:

```text
GET  /api/v1/me/submissions
POST /api/v1/me/submissions
GET  /api/v1/me/submissions/{id}
PATCH /api/v1/me/submissions/{id}
POST /api/v1/me/submissions/{id}/submit
```

Internally:

```text
FrontendSubmissionController
→ ContentService
→ EditorialWorkflow
```

No duplicate content repository.

---

# 50. Search API

```text
GET /api/v1/search
```

Parameters:

```text
q
type
category
tag
author
page
per_page
```

Example:

```text
/api/v1/search?q=ai&type=post&page=1
```

---

# 51. Search Service Contract

```php
interface SearchServiceInterface
{
    public function search(
        SearchQuery $query
    ): PaginatedResult;
}
```

Search API does not know whether backend uses:

```text
FULLTEXT
fallback SQL
future external search
```

---

# 52. Multisite Admin API

Super/site administrator endpoints:

```text
GET    /api/v1/sites
POST   /api/v1/sites
GET    /api/v1/sites/{id}
PATCH  /api/v1/sites/{id}
```

Domain:

```text
GET    /api/v1/sites/{id}/domains
POST   /api/v1/sites/{id}/domains
DELETE /api/v1/sites/{id}/domains/{domainId}
```

Only permitted actors may access.

---

# 53. Site Service Contract

```php
interface SiteServiceInterface
{
    public function create(
        CreateSiteData $data,
        ActorInterface $actor
    ): Site;

    public function update(
        int $siteId,
        UpdateSiteData $data,
        ActorInterface $actor
    ): Site;

    public function addDomain(
        int $siteId,
        string $domain,
        ActorInterface $actor
    ): SiteDomain;
}
```

Domain changes emit:

```text
site.updated
```

and regenerate domain map cache.

---

# 54. Settings API

```text
GET /api/v1/settings/{namespace}
PUT /api/v1/settings/{namespace}
```

Examples:

```text
/api/v1/settings/seo
/api/v1/settings/notifications
/api/v1/settings/analytics
/api/v1/settings/permalink
```

Do not expose all settings and secrets through one giant endpoint.

---

# 55. Settings Service Contract

```php
interface SettingsServiceInterface
{
    public function get(
        string $namespace,
        string $key,
        mixed $default = null
    ): mixed;

    public function set(
        string $namespace,
        string $key,
        mixed $value,
        ActorInterface $actor
    ): void;

    public function getNamespace(
        string $namespace
    ): array;
}
```

---

# 56. Plugin Admin API

```text
GET  /api/v1/plugins
POST /api/v1/plugins/{slug}/activate
POST /api/v1/plugins/{slug}/deactivate
```

No automatic marketplace/update.

Plugin installation from filesystem is discovered by system.

Required:

```text
plugin.manage
```

---

# 57. Plugin Service Contract

```php
interface PluginManagerInterface
{
    public function discover(): array;

    public function activate(
        string $slug,
        ActorInterface $actor
    ): void;

    public function deactivate(
        string $slug,
        ActorInterface $actor
    ): void;

    public function active(): array;
}
```

---

# 58. Theme API

```text
GET  /api/v1/themes
POST /api/v1/themes/{slug}/activate
```

Required:

```text
theme.manage
```

Theme activation emits:

```text
theme.activated
```

which invalidates public page cache.

---

# 59. Advertising API

```text
GET    /api/v1/ads
POST   /api/v1/ads
GET    /api/v1/ads/{id}
PATCH  /api/v1/ads/{id}
DELETE /api/v1/ads/{id}
```

Rules:

```text
GET    /api/v1/ads/{id}/rules
POST   /api/v1/ads/{id}/rules
PATCH  /api/v1/ad-rules/{id}
DELETE /api/v1/ad-rules/{id}
```

Required:

```text
ads.manage
```

---

# 60. Advertising Service

```php
interface AdvertisingServiceInterface
{
    public function resolve(
        string $placement,
        AdContext $context
    ): ?Ad;

    public function create(
        CreateAdData $data,
        ActorInterface $actor
    ): Ad;
}
```

Frontend theme asks service for placement.

Theme MUST NOT query ads table.

---

# 61. Notification Settings API

```text
GET /api/v1/settings/notifications
PUT /api/v1/settings/notifications
```

Example:

```json
{
  "content_submitted": {
    "internal": true,
    "email": false
  },
  "revision_requested": {
    "internal": true,
    "email": true
  },
  "content_published": {
    "internal": false,
    "email": false
  }
}
```

---

# 62. Notification Listing

```text
GET /api/v1/me/notifications
POST /api/v1/me/notifications/{id}/read
POST /api/v1/me/notifications/read-all
```

---

# 63. Migration API

WordPress migration MUST NOT be publicly accessible.

Potential admin endpoints:

```text
POST /api/v1/migrations/wordpress/analyze
POST /api/v1/migrations/wordpress/run
GET  /api/v1/migrations/wordpress/status
GET  /api/v1/migrations/wordpress/logs
```

For shared hosting, migration MAY also run through CLI/browser-safe batch runner.

---

# 64. Migration Service Contract

```php
interface WordPressMigrationServiceInterface
{
    public function analyze(
        WordPressSourceConfig $source
    ): MigrationAnalysis;

    public function importBatch(
        MigrationBatchRequest $request
    ): MigrationBatchResult;
}
```

Batch processing required to avoid:

```text
PHP timeout
memory exhaustion
```

---

# 65. Import Through Service Layer

Migration mapping:

```text
WordPress row
      ↓
Mapper
      ↓
CreateContentData
      ↓
ContentService
```

For large import, specialized bulk service MAY exist but MUST preserve:

```text
site isolation
slug rules
URL registry
media ownership
SEO mapping
```

---

# 66. Internal Query Services

Read-heavy operations SHOULD use explicit query services.

Example:

```php
interface ContentQueryServiceInterface
{
    public function latest(
        ContentQuery $query
    ): PaginatedResult;

    public function archive(
        ArchiveQuery $query
    ): PaginatedResult;
}
```

This prevents forcing write-oriented domain objects into every public list request.

---

# 67. CQRS Scope

CMS does **not** implement full CQRS architecture.

But separation is encouraged:

```text
Commands
→ mutation services

Queries
→ optimized query services
```

especially for high-traffic frontend reads.

---

# 68. Content Query DTO

```php
final class ContentQuery
{
    public ?string $type = null;
    public ?string $status = null;
    public ?int $authorId = null;
    public ?int $termId = null;

    public int $page = 1;
    public int $perPage = 20;

    public string $orderBy = 'published_at';
    public string $order = 'desc';
}
```

Whitelist `orderBy`.

Never place raw request field into SQL ORDER BY.

---

# 69. Pagination Contract

Default:

```text
page = 1
per_page = 20
```

Maximum:

```text
per_page = 100
```

Invalid values return:

```text
VALIDATION_ERROR
```

---

# 70. Sorting

Allowed generic public sort:

```text
published_at
created_at
title
```

No arbitrary column sorting.

---

# 71. Filtering

Filters must be explicit.

Example:

```text
?category=technology
```

Controller resolves category slug and passes normalized query to service.

Repository does not parse HTTP params.

---

# 72. Field Selection

`fields=` optimization MAY be introduced later.

Not required v1.

Avoid premature API complexity.

---

# 73. API Cache

Anonymous public GET endpoints MAY use cache.

Logical key example:

```text
api:{site_id}:posts:{normalized_query_hash}
```

Mutation triggers relevant invalidation through domain events.

---

# 74. ETag

Public content API SHOULD support:

```text
ETag
```

where useful.

If client sends:

```text
If-None-Match
```

server MAY return:

```text
304 Not Modified
```

---

# 75. Rate Limiting

Mandatory minimal rate limit for:

```text
login
forgot password
comment create
user registration if enabled
```

Rate limiting implementation should work without Redis.

Use lightweight:

```text
filesystem
or
database only when necessary
```

---

# 76. Permission Enforcement

Controller MAY have route-level capability middleware.

But service MUST still enforce important authorization.

Reason:

services can also be invoked by:

```text
plugins
migration
internal tools
```

Never trust controller as only permission boundary.

---

# 77. Ownership Policy

Example contributor update:

Allowed when:

```text
actor can content.edit_own
AND
content.author_id = actor.id
```

or:

```text
actor can content.edit_all
```

Implement through policy/service, not repeated controller conditionals.

---

# 78. Authorization Service

```php
interface AuthorizationServiceInterface
{
    public function can(
        ActorInterface $actor,
        string $capability,
        mixed $resource = null
    ): bool;

    public function authorize(
        ActorInterface $actor,
        string $capability,
        mixed $resource = null
    ): void;
}
```

`authorize()` throws standardized forbidden exception.

---

# 79. API Resource Layer

Domain entity MUST NOT automatically serialize every field.

Use explicit resource:

```text
ContentPublicResource
ContentAdminResource
UserPublicResource
UserAdminResource
MediaResource
```

This prevents accidental private data leak.

---

# 80. Public vs Admin Content Resource

Public:

```text
title
slug
html
author public info
public terms
SEO
published date
```

Admin:

```text
blocks
workflow status
revision information
internal timestamps
editorial notes where permitted
```

---

# 81. Date Format

REST API uses ISO 8601 UTC.

Example:

```text
2026-10-24T06:30:00Z
```

Admin UI converts to site timezone.

---

# 82. Null Policy

Use JSON `null` for intentionally absent optional field.

Do not mix:

```text
""
null
false
```

without semantic reason.

---

# 83. Boolean Policy

Always actual JSON boolean:

```json
{
  "robots_index": true
}
```

Not:

```json
{
  "robots_index": "1"
}
```

---

# 84. ID Policy

API IDs are integers in v1.

Example:

```json
{
  "id": 182
}
```

Not strings unless external identifier.

---

# 85. Slug Contract

Slug normalization service:

```php
interface SlugServiceInterface
{
    public function generate(string $value): string;

    public function ensureUnique(
        string $candidate,
        string $entityType,
        ?int $ignoreId = null
    ): string;
}
```

One centralized implementation.

---

# 86. URL Contract

URL must be generated through:

```php
UrlGeneratorInterface
```

API resources MUST NOT concatenate:

```text
domain + slug
```

manually.

---

# 87. Cache Service Contract

```php
interface CacheInvalidationServiceInterface
{
    public function invalidateDependency(
        string $dependency
    ): void;

    public function invalidateContent(
        int $contentId
    ): void;

    public function invalidateSite(
        int $siteId
    ): void;
}
```

Feature services generally emit events rather than calling cache directly.

---

# 88. Event Side-Effect Contract

Example `content.updated` listeners:

```text
CacheInvalidationListener
SitemapInvalidationListener
PluginHookListener
```

Example `content.published` additionally:

```text
NotificationListener
```

---

# 89. Required Content Events

Create:

```text
content.created
```

Update:

```text
content.updated
```

Submission:

```text
content.submitted
```

Revision:

```text
content.revision_requested
```

Approval:

```text
content.approved
```

Schedule:

```text
content.scheduled
```

Publish:

```text
content.published
```

Archive:

```text
content.archived
```

Delete:

```text
content.deleted
```

---

# 90. Event Payload Baseline

`content.updated`

```json
{
  "content_id": 182,
  "content_type": "post",
  "changed_fields": [
    "title",
    "content"
  ]
}
```

`content.published`

```json
{
  "content_id": 182,
  "content_type": "post",
  "published_at": "2026-10-24T05:00:00Z"
}
```

---

# 91. Changed Fields

Content service SHOULD track high-level changed fields.

Examples:

```text
title
slug
content
excerpt
featured_media
terms
seo
status
```

Cache agent MAY use information for targeted invalidation.

---

# 92. Service Exception Contract

Application services throw typed exceptions.

Examples:

```text
ValidationException
AuthorizationException
ContentNotFoundException
InvalidStateTransitionException
SlugConflictException
MediaValidationException
```

Controller maps exceptions to HTTP response.

---

# 93. No HTTP Exceptions in Domain

ContentService MUST NOT throw:

```text
Http404Exception
Http422Exception
```

It throws domain/application exception.

HTTP layer translates.

---

# 94. Transaction Service

```php
interface TransactionManagerInterface
{
    public function run(callable $callback): mixed;
}
```

Example:

```php
$transaction->run(function () {
    // content
    // terms
    // revision
});
```

---

# 95. Domain Event Dispatch After Commit

Preferred:

```text
BEGIN
write
write
write
COMMIT
↓
dispatch events
```

No email or heavy plugin callbacks inside transaction.

---

# 96. Plugin Internal Service Access

Plugins MAY request approved public contracts from container.

Example:

```text
ContentServiceInterface
EventDispatcherInterface
SettingsServiceInterface
```

Plugins MUST NOT depend on concrete implementation classes unless explicitly documented as public plugin API.

---

# 97. Plugin Route Namespace

Plugin API routes MUST use:

```text
/api/v1/plugins/{plugin-slug}/...
```

unless extending a documented core resource.

This reduces collision.

---

# 98. Plugin Permission

Plugins register capabilities:

```text
newsletter.manage
poll.manage
```

Permission keys MUST use plugin/domain namespace.

---

# 99. API Versioning Rule

Breaking API change requires:

```text
/api/v2
```

or backward-compatible transition plan.

Adding optional response field is generally non-breaking.

Renaming existing field is breaking.

---

# 100. Internal Contract Versioning

Internal service interface breaking changes require architecture coordination.

Core shared contract:

```text
v1
```

Agents MUST NOT independently change signatures.

---

# 101. Example Full Publish Flow

```text
POST /api/v1/content/182/publish
        ↓
Authentication Middleware
        ↓
SiteContext
        ↓
Publish Controller
        ↓
EditorialWorkflow.publish()
        ↓
AuthorizationService
        ↓
ContentRepository
        ↓
RevisionService
        ↓
URL Registry
        ↓
Transaction COMMIT
        ↓
content.published
        ↓
 ┌──────────────┬──────────────┬──────────────┐
 ↓              ↓              ↓              ↓
Cache          SEO        Notification      Plugin
Invalidation  Sitemap        Service         Hooks
        ↓
ContentAdminResource
        ↓
200 OK
```

---

# 102. Example Frontend Submission Flow

```text
Contributor
   ↓
POST /api/v1/me/submissions
   ↓
FrontendSubmissionController
   ↓
ContentService.create()
   ↓
status=draft
```

Then:

```text
POST /api/v1/me/submissions/55/submit
   ↓
EditorialWorkflow.submit()
   ↓
status=submitted
   ↓
content.submitted
   ↓
optional notification
```

---

# 103. Example Cache Invalidation Flow

Editor changes title:

```text
PATCH /api/v1/content/182
```

Service:

```text
save
revision
commit
emit content.updated
```

Cache listener invalidates:

```text
single
homepage
category
tag
author archive
API result
```

Next public request renders current version.

---

# 104. Public API Query Budget

Cached:

```text
0 DB queries
```

Uncached collection:

```text
target <= 5 logical queries
```

Single:

```text
target <= 5 logical queries
```

API agent MUST NOT create N+1 resource serialization.

---

# 105. Resource Serializer Rule

Wrong:

```text
for each post:
    authorRepository.find()
    mediaRepository.find()
    termRepository.find()
```

Correct:

```text
query posts
batch authors
batch media
batch terms
serialize
```

---

# 106. Admin API Query Budget

Less strict than public API but N+1 remains forbidden.

Admin list pages SHOULD remain below reasonable query count independent of row count.

---

# 107. API Security Tests

Every protected mutation must test:

```text
anonymous → 401
wrong role → 403
correct role → success
other site resource → 404
```

---

# 108. Multisite API Isolation

Given site A request:

```text
Host: site-a.com
```

Attempt:

```text
GET /api/v1/content/{site-b-content-id}
```

Expected:

```text
404 CONTENT_NOT_FOUND
```

not:

```text
403
```

to avoid leaking existence.

---

# 109. Domain Context

Site is resolved from request hostname.

Clients MUST NOT select arbitrary:

```text
site_id
```

through request parameter for normal site API.

This is forbidden:

```text
GET /api/v1/posts?site_id=2
```

for standard tenant routes.

---

# 110. CSRF Contract

Session-authenticated mutation endpoints require CSRF token.

Example header:

```text
X-CSRF-Token
```

Exact implementation may vary but contract must be consistent.

---

# 111. File Upload Limit

Configurable:

```text
media.max_upload_size
```

Controller/service must validate against both:

```text
application limit
PHP upload limit
```

---

# 112. Raw HTML Block Permission

Because raw HTML is dangerous, saving raw HTML block MAY require:

```text
content.unfiltered_html
```

Contributor without capability receives sanitization or rejection.

---

# 113. Preview API

Protected preview:

```text
GET /api/v1/content/{id}/preview
```

or frontend preview token route.

Preview MUST:

```text
not be publicly cached
not be indexed
require permission/token
```

---

# 114. Scheduled Content Public Access

Scheduled content behaves as unpublished until:

```text
scheduled_at <= current UTC
AND
publish process executed
```

Public API MUST NOT expose future content by default.

---

# 115. Sitemap API

Sitemap itself is not JSON API requirement.

Public routes:

```text
/sitemap.xml
/sitemap-posts-1.xml
```

SEO service can expose internal methods:

```php
interface SitemapServiceInterface
{
    public function invalidateSite(int $siteId): void;

    public function generate(int $siteId): void;
}
```

---

# 116. Health Endpoint

Optional lightweight:

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

MUST NOT reveal:

```text
DB credentials
filesystem paths
PHP config secrets
plugin stack traces
```

---

# 117. Internal Admin Diagnostics

Authorized endpoint MAY provide:

```text
cache status
PHP version
DB version
write permissions
cron status
```

Recommended:

```text
GET /api/v1/system/diagnostics
```

Only:

```text
site.manage
```

---

# 118. REST Contract Freeze v1

The following are frozen before agent parallel implementation:

```text
/api/v1 base path

response envelope

error envelope

error code style

content mutation endpoints

workflow action endpoints

media upload endpoint

SEO endpoint

taxonomy endpoint

authentication endpoints

pagination format

ISO 8601 timestamps

public API read-only policy
```

---

# 119. Internal Service Contract Freeze v1

Freeze:

```text
AuthenticationServiceInterface
AuthorizationServiceInterface

SiteServiceInterface

ContentServiceInterface
ContentQueryServiceInterface

EditorialWorkflowInterface

RevisionServiceInterface

TaxonomyServiceInterface

MediaServiceInterface

SeoServiceInterface

SearchServiceInterface

SettingsServiceInterface

PluginManagerInterface

AdvertisingServiceInterface

CacheInterface
EventDispatcherInterface
TransactionManagerInterface
UrlGeneratorInterface
```

---

# 120. Agent Ownership

### API Agent

Owns:

```text
routing
controllers
request validation adapters
resources
HTTP status mapping
error serialization
pagination serialization
```

Does not own domain logic.

### Content Agent

Owns:

```text
ContentService
ContentQueryService
Content DTOs
```

### Workflow Agent

Owns:

```text
EditorialWorkflow
transition rules
```

### Identity Agent

Owns:

```text
AuthenticationService
AuthorizationService
Actor
```

### Media Agent

Owns:

```text
MediaService
upload processing
```

### SEO Agent

Owns:

```text
SeoService
RedirectService
SitemapService
```

### Multisite Agent

Owns:

```text
SiteService
SiteContext
Domain Resolver
```

---

# 121. API Agent Forbidden Actions

API Agent MUST NOT:

```text
write SQL
duplicate validation business rules already owned by service
directly invalidate cache
directly send email
directly manipulate workflow status
directly write SEO table
directly write media table
bypass SiteContext
```

---

# 122. Definition of Done — Endpoint

An endpoint is complete only when:

1. route exists;
2. authentication policy defined;
3. authorization policy defined;
4. validation defined;
5. service method called;
6. standardized success response used;
7. standardized errors mapped;
8. site isolation tested;
9. security tests exist;
10. query count reviewed when public-facing.

---

# 123. Contract Test Example — Publish

Given:

```text
approved article
authorized editor
```

When:

```text
POST /api/v1/content/1/publish
```

Expect:

```text
200
status = published
published_at set
workflow log created
content.published emitted
cache invalidated
```

---

# 124. Contract Test — Invalid Publish

Given:

```text
draft article
```

When unauthorized transition attempted:

Expected:

```text
422 INVALID_STATE_TRANSITION
```

or `403` when transition is valid but actor lacks permission.

These cases MUST remain distinct.

---

# 125. Contract Test — Multisite

Site A actor requests Site B content.

Expected:

```text
404 CONTENT_NOT_FOUND
```

---

# 126. Contract Test — Public Mutation

Anonymous:

```text
POST /api/v1/content
```

Expected:

```text
401 UNAUTHENTICATED
```

---

# 127. Contract Test — Contributor

Contributor creates own draft:

```text
success
```

Contributor publishes directly without permission:

```text
403 FORBIDDEN
```

Contributor submits:

```text
success
```

---

# 128. Contract Test — Media

Invalid executable upload:

```text
422 INVALID_MEDIA_TYPE
```

Valid image:

```text
201
variants created
relative storage path persisted
```

---

# 129. Contract Test — API Serialization

User resource MUST NOT contain:

```text
password_hash
reset tokens
internal secrets
```

even for accidental entity additions.

Explicit resource serialization prevents regression.

---

# 130. Final Integration Principle

HTTP API is only an adapter.

It must remain possible to perform:

```text
publish content
create content
update SEO
upload media
create term
```

without HTTP.

For example:

```text
migration
cron
plugin
test
```

can invoke the same services.

Therefore:

```text
HTTP != Business Logic
```

The canonical architecture is:

```text
Presentation
    ↓
Application Contract
    ↓
Domain
    ↓
Persistence
```

---

# 131. Next Deliverable

After this specification is frozen, the next artifact should be:

**Agent Task Pack**

It will produce one implementation brief per coding agent containing:

```text
Agent role
owned modules
allowed files
forbidden files
dependencies
contracts consumed
contracts provided
database ownership
events emitted
events consumed
implementation tasks
acceptance criteria
required tests
merge checklist
```

This pack can then be handed directly to autonomous coding agents without asking each agent to redesign the architecture.