# Technical Architecture & Shared Contracts Specification

**Project:** Lightweight Portal CMS  
**Document Version:** 1.0  
**Status:** Architecture Baseline  
**Target Runtime:** PHP 8.x + MySQL/MariaDB + Apache  
**Deployment Target:** Standard Shared Hosting  
**Production Node.js Dependency:** None  
**Architecture Style:** Modular Monolith + Event-Driven Internal Architecture + Cache-First Frontend  
**Primary Objective:** Database-efficient publishing platform suitable for multi-agent implementation

---

# 1. Purpose

Dokumen ini merupakan kontrak teknis utama yang harus dipatuhi seluruh coding agent.

PRD menjelaskan **apa yang harus dibuat**.

Dokumen ini menjelaskan:

- bagaimana sistem dibangun;
- bagaimana module berkomunikasi;
- siapa memiliki data tertentu;
- bagaimana database diakses;
- bagaimana event digunakan;
- bagaimana API dibentuk;
- bagaimana cache bekerja;
- bagaimana multisite diisolasi;
- bagaimana plugin berinteraksi dengan core;
- bagaimana agent dapat bekerja paralel tanpa mengubah domain milik agent lain.

Jika implementasi bertentangan dengan dokumen ini, implementasi harus dianggap salah kecuali Architecture Contract secara eksplisit diperbarui.

---

# 2. Normative Language

Dokumen menggunakan istilah:

**MUST**  
Wajib dilakukan.

**MUST NOT**  
Dilarang dilakukan.

**SHOULD**  
Sangat direkomendasikan kecuali terdapat alasan teknis kuat.

**MAY**  
Opsional.

---

# 3. Architecture Goals

Sistem MUST memenuhi prinsip berikut.

## 3.1 Shared Hosting First

Production environment hanya diasumsikan memiliki:

```text
Apache
PHP 8.x
MySQL / MariaDB
Filesystem
Cron jika tersedia
```

Sistem MUST NOT membutuhkan:

```text
Node.js runtime
Redis
Memcached
RabbitMQ
Kafka
Elasticsearch
Supervisor
Docker
Long-running worker
WebSocket server
```

Node.js MAY digunakan dalam development untuk:

```text
Vite
Vue
React
CSS build
JS bundling
```

hasil akhirnya harus berupa asset statis.

---

# 4. Architectural Style

Sistem menggunakan:

**Modular Monolith**

bukan microservices.

Struktur konseptual:

```text
                   ┌─────────────────┐
                   │      Apache     │
                   └────────┬────────┘
                            │
                ┌───────────┴───────────┐
                │                       │
          Public Frontend           Admin/API
                │                       │
                └───────────┬───────────┘
                            │
                      Application Core
                            │
        ┌───────────────────┼──────────────────┐
        │                   │                  │
     Domains            Event Bus            Cache
        │                                      │
    Repositories                           Filesystem
        │
      MySQL
```

Seluruh module berjalan dalam satu aplikasi PHP tetapi mempunyai boundary jelas.

---

# 5. Fundamental Architecture Rule

Dependency utama:

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

Yang diperbolehkan:

```text
Controller → Service
Service → Repository Interface
Service → Event Dispatcher
Service → Domain Object
Repository → Database
```

Yang dilarang:

```text
Controller → SQL
Theme → SQL
Plugin → Core Table SQL
Block Renderer → SQL
Template → Repository
JavaScript UI → Database
SEO module → Content table direct SQL
Cache module → Content table direct SQL
```

---

# 6. High-Level Module Map

Core modules:

```text
Core
Site / Multisite
Identity
Content
Taxonomy
Editorial
Revision
Media
SEO
Cache
Theme
Plugin
Block
Comment
Search
Notification
Advertising
Integration
REST API
Frontend Submission
Migration
```

Dependency ideal:

```text
                         Core
                          │
               ┌──────────┴──────────┐
               ↓                     ↓
             Site                 Identity
               │                     │
               └──────────┬──────────┘
                          ↓
                       Content
                 ┌────────┼────────┐
                 ↓        ↓        ↓
              Taxonomy  Media   Revision
                 │        │
                 └───┬────┘
                     ↓
                  Editorial
                     │
         ┌───────────┼─────────────┐
         ↓           ↓             ↓
        SEO         Cache        Search
         │           │
         └─────┬─────┘
               ↓
          Theme / API
               ↓
           Frontend
```

Plugin boleh memperluas layer tetapi tidak boleh memotong boundary.

---

# 7. Source Code Structure

Struktur target:

```text
/
├── app/
│   ├── Core/
│   │   ├── Bootstrap/
│   │   ├── Config/
│   │   ├── Container/
│   │   ├── Database/
│   │   ├── Events/
│   │   ├── Exceptions/
│   │   ├── Filesystem/
│   │   ├── Http/
│   │   ├── Logging/
│   │   ├── Routing/
│   │   ├── Security/
│   │   └── Support/
│   │
│   ├── Modules/
│   │   ├── Site/
│   │   ├── Identity/
│   │   ├── Content/
│   │   ├── Taxonomy/
│   │   ├── Editorial/
│   │   ├── Revision/
│   │   ├── Media/
│   │   ├── Seo/
│   │   ├── Cache/
│   │   ├── Theme/
│   │   ├── Plugin/
│   │   ├── Block/
│   │   ├── Comment/
│   │   ├── Search/
│   │   ├── Notification/
│   │   ├── Advertising/
│   │   └── Integration/
│   │
│   └── Shared/
│       ├── Contracts/
│       ├── DTO/
│       ├── Enums/
│       └── ValueObjects/
│
├── admin/
├── api/
├── public/
│   ├── index.php
│   ├── assets/
│   └── uploads/
│
├── plugins/
├── themes/
├── config/
├── database/
│   └── migrations/
├── storage/
│   ├── cache/
│   ├── logs/
│   ├── sessions/
│   └── temp/
├── tests/
└── bin/
```

---

# 8. Module Internal Structure

Setiap module SHOULD menggunakan struktur:

```text
Content/
├── Application/
│   ├── DTO/
│   ├── Queries/
│   └── Services/
│
├── Domain/
│   ├── Entities/
│   ├── Events/
│   ├── Exceptions/
│   └── ValueObjects/
│
├── Infrastructure/
│   ├── Persistence/
│   └── Providers/
│
└── Http/
    ├── Controllers/
    ├── Requests/
    └── Resources/
```

Tidak wajib menerapkan DDD penuh.

Tujuannya hanya menjaga boundary.

---

# 9. Shared Contracts

Folder:

```text
app/Shared/Contracts/
```

Contract yang sudah dipublikasikan tidak boleh diubah sembarangan oleh satu agent.

Perubahan breaking MUST:

1. meningkatkan contract version;
2. mendokumentasikan migration path;
3. mengidentifikasi module terdampak.

---

# 10. Site Context Contract

Seluruh operasi tenant-specific MUST mengetahui site aktif.

```php
interface SiteContextInterface
{
    public function id(): int;

    public function domain(): string;

    public function isResolved(): bool;
}
```

Site resolver:

```php
interface SiteResolverInterface
{
    public function resolve(string $host): SiteContextInterface;
}
```

Tidak boleh ada fallback diam-diam ke Site ID `1`.

Jika site tidak dapat ditemukan:

```text
SiteNotFoundException
```

harus dilempar.

---

# 11. Site Isolation Rule

Setiap repository site-specific MUST otomatis menerapkan `site_id`.

Developer MUST NOT menulis:

```php
$contentRepository->find($id, $siteId);
```

jika SiteContext telah tersedia.

Lebih disukai:

```php
$contentRepository->find($id);
```

dan repository mengambil `site_id` dari SiteContext.

Tujuannya mencegah kebocoran antar-site.

---

# 12. Core Entity Identifiers

Semua primary key core menggunakan:

```text
BIGINT UNSIGNED AUTO_INCREMENT
```

kecuali ada alasan kuat untuk bentuk lain.

Public API MAY menggunakan opaque identifier di masa depan, tetapi internal database tidak membutuhkan UUID pada MVP.

---

# 13. Timestamp Standard

Database menggunakan:

```text
UTC
```

untuk seluruh timestamp.

Field standar:

```text
created_at
updated_at
```

Tanggal publishing:

```text
published_at
scheduled_at
```

Timezone site disimpan pada Site Settings.

Formatting timezone hanya dilakukan presentation layer.

---

# 14. Content Entity Contract

Canonical Content representation:

```php
final class Content
{
    public int $id;
    public int $siteId;
    public string $type;
    public int $authorId;

    public string $title;
    public string $slug;
    public ?string $excerpt;

    public array $contentBlocks;
    public string $compiledHtml;
    public string $plainText;

    public string $status;

    public ?int $featuredMediaId;

    public ?DateTimeImmutable $publishedAt;
    public ?DateTimeImmutable $scheduledAt;

    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;
}
```

Content status contract:

```text
draft
submitted
review
revision_requested
approved
scheduled
published
archived
trash
```

Agent MUST NOT membuat status baru tanpa memperbarui contract.

---

# 15. Content Type Registry

Content type tidak boleh hard-coded ke:

```text
post
page
```

Registry:

```php
interface ContentTypeRegistryInterface
{
    public function register(
        string $name,
        array $definition
    ): void;

    public function has(string $name): bool;

    public function get(string $name): ContentTypeDefinition;
}
```

Contoh definition:

```php
[
    'label' => 'Article',
    'public' => true,
    'has_archive' => true,
    'supports' => [
        'title',
        'editor',
        'featured_media',
        'excerpt',
        'comments'
    ]
];
```

---

# 16. Content Repository Contract

```php
interface ContentRepositoryInterface
{
    public function find(int $id): ?Content;

    public function findBySlug(
        string $type,
        string $slug
    ): ?Content;

    public function save(Content $content): Content;

    public function delete(int $id): void;

    public function paginate(
        ContentQuery $query
    ): PaginatedResult;
}
```

Repository MUST:

- apply site scope;
- prevent N+1 where possible;
- use prepared statements;
- return domain objects/DTOs;
- never return raw PDO result outside Infrastructure.

---

# 17. Content Application Service

All content mutation MUST go through:

```php
interface ContentServiceInterface
{
    public function create(
        CreateContentData $data,
        Actor $actor
    ): Content;

    public function update(
        int $id,
        UpdateContentData $data,
        Actor $actor
    ): Content;

    public function publish(
        int $id,
        Actor $actor
    ): Content;

    public function delete(
        int $id,
        Actor $actor
    ): void;
}
```

UI, API, plugin, import process dan frontend submission menggunakan service yang sama.

---

# 18. Transaction Contract

Mutation penting MUST menjalankan database transaction.

Contoh:

```text
BEGIN

update content
update taxonomy relationships
save SEO
create revision
write workflow log

COMMIT

dispatch domain events
```

Event yang memicu side-effect sebaiknya dikirim **setelah transaction berhasil commit**.

---

# 19. Event Contract

Event naming:

```text
domain.entity.action
```

Contoh canonical:

```text
site.created
site.updated
site.deleted

user.created
user.updated
user.deleted

content.created
content.updated
content.submitted
content.review_started
content.revision_requested
content.approved
content.scheduled
content.published
content.unpublished
content.archived
content.deleted

taxonomy.created
taxonomy.updated
taxonomy.deleted

term.created
term.updated
term.deleted

media.created
media.updated
media.deleted

comment.created
comment.approved
comment.deleted

theme.activated

plugin.activated
plugin.deactivated

settings.updated
```

---

# 20. Event Object Contract

```php
interface DomainEventInterface
{
    public function name(): string;

    public function occurredAt(): DateTimeImmutable;

    public function siteId(): ?int;

    public function payload(): array;
}
```

Event payload MUST berisi data minimum.

Jangan memasukkan object besar jika hanya ID yang diperlukan.

Contoh:

```php
[
    'content_id' => 182,
    'content_type' => 'post',
    'old_status' => 'approved',
    'new_status' => 'published'
]
```

---

# 21. Event Dispatcher

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

Dispatcher MVP synchronous.

Tidak ada queue daemon.

Listener MUST NOT menyebabkan circular event loop.

---

# 22. Event Failure Policy

Critical operation:

```text
save content
```

tidak boleh rollback hanya karena:

```text
email notification gagal
analytics hook gagal
optional plugin listener gagal
```

Listener dibagi:

```text
critical
non-critical
```

Core consistency listener seperti cache invalidation dapat diberi treatment khusus.

Optional plugin failure harus di-log dan diisolasi sebisa mungkin.

---

# 23. Identity Contract

User entity:

```php
final class User
{
    public int $id;
    public int $siteId;

    public string $username;
    public string $email;
    public string $passwordHash;

    public string $displayName;
    public string $status;

    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;
}
```

User MUST selalu site-specific.

Email yang sama MAY digunakan pada site berbeda.

---

# 24. Actor Contract

Authorization layer menggunakan Actor abstraction.

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

Business service tidak boleh membaca session global langsung.

---

# 25. Capability Contract

Capability default:

```text
content.create
content.read_private
content.edit_own
content.edit_all
content.delete_own
content.delete_all
content.submit
content.review
content.approve
content.publish
content.schedule

media.upload
media.edit
media.delete

comment.moderate

user.manage

seo.manage
redirect.manage

theme.manage
plugin.manage
site.manage
settings.manage
ads.manage
```

Role hanyalah kumpulan capability.

---

# 26. Taxonomy Contracts

Taxonomy registry:

```php
interface TaxonomyRegistryInterface
{
    public function register(
        string $name,
        TaxonomyDefinition $definition
    ): void;

    public function get(string $name): TaxonomyDefinition;
}
```

Default:

```text
category
tag
```

Relationship:

```text
content ↔ terms
```

harus memakai junction table.

---

# 27. Block Contract

Block representation minimum:

```json
{
  "type": "paragraph",
  "version": 1,
  "data": {}
}
```

Semua blocks MUST memiliki:

```text
type
version
data
```

Tidak boleh menyimpan konfigurasi block random di root object.

---

# 28. Block Registry

```php
interface BlockRegistryInterface
{
    public function register(
        string $type,
        BlockDefinition $definition
    ): void;

    public function get(string $type): BlockDefinition;

    public function has(string $type): bool;
}
```

Definition mencakup:

```text
schema
renderer
sanitizer
version
migration
```

---

# 29. Block Renderer Rule

Block renderer MUST:

- deterministic;
- menerima data block;
- menghasilkan HTML;
- tidak melakukan SQL query;
- tidak mengakses global state;
- tidak memanggil repository langsung.

Untuk dynamic block seperti related posts:

renderer menerima pre-resolved context dari rendering service atau menggunakan dedicated resolver sebelum rendering.

---

# 30. Block Versioning

Contoh:

```json
{
  "type": "gallery",
  "version": 2,
  "data": {}
}
```

Jika schema berubah:

```text
v1 → migration → v2
```

Old content MUST tetap dapat dibuka.

---

# 31. Content Compilation

Pada content save:

```text
Block JSON
   ↓
Validation
   ↓
Sanitize
   ↓
Compile
   ↓
HTML
   ↓
Plain Text
   ↓
Store
```

Field:

```text
content_json
content_html
content_plain
```

Tujuan:

- frontend tidak compile blocks setiap request;
- search dapat menggunakan plain text;
- migration tetap mempunyai structured content.

---

# 32. Revision Contract

Revision menyimpan snapshot konten utama.

Maksimal:

```text
3 persistent revisions/content
```

Saat revision baru dibuat:

```text
DELETE oldest revision
IF revision_count >= 3
```

Autosave tidak dihitung revision.

---

# 33. SEO Contract

SEO entity:

```php
final class SeoMetadata
{
    public int $contentId;

    public ?string $title;
    public ?string $description;
    public ?string $canonical;

    public bool $index;
    public bool $follow;

    public ?string $ogTitle;
    public ?string $ogDescription;
    public ?int $ogImageId;

    public ?string $schemaType;
}
```

SEO service:

```php
interface SeoServiceInterface
{
    public function getForContent(int $contentId): SeoMetadata;

    public function saveForContent(
        int $contentId,
        SeoMetadata $metadata
    ): void;
}
```

---

# 34. SEO Rendering Rule

Theme tidak menghasilkan SEO tags sendiri.

Theme MUST memanggil centralized SEO rendering service.

Tujuan:

```text
canonical
OG
robots
schema
title
```

selalu konsisten.

---

# 35. Media Contract

Media entity minimum:

```text
id
site_id
user_id
path
mime_type
extension
width
height
filesize
alt_text
caption
created_at
```

Variant media disimpan terstruktur.

Contoh:

```json
{
  "thumbnail": {
    "path": "...",
    "width": 320,
    "height": 180
  },
  "medium": {},
  "large": {},
  "webp": {}
}
```

---

# 36. Media Storage Rule

URL tidak boleh disimpan sebagai absolute domain pada record utama.

Store:

```text
relative path
```

Contoh:

```text
2026/10/image-name.webp
```

Domain dibentuk saat runtime berdasarkan active site.

Hal ini penting untuk domain mapping.

---

# 37. Upload Security Contract

File upload MUST:

1. validate upload error;
2. enforce size limit;
3. inspect MIME;
4. whitelist extension;
5. generate safe filename;
6. prevent PHP/script execution;
7. reject double extension berbahaya.

Uploads directory MUST NOT execute PHP.

---

# 38. Search Contract

Search service:

```php
interface SearchServiceInterface
{
    public function search(
        SearchQuery $query
    ): PaginatedResult;
}
```

SearchQuery minimum:

```text
keyword
content_type
author
taxonomy
page
per_page
```

Backend menggunakan FULLTEXT jika tersedia.

Fallback implementation diperbolehkan.

---

# 39. Cache Architecture

Cache dibagi menjadi:

```text
Request Cache
Object Cache
Fragment Cache
Full Page Cache
Generated Artifact Cache
```

Generated artifact:

```text
sitemap
feed
compiled configurations
domain mapping
```

---

# 40. Cache Interface Contract

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

    public function delete(string $key): void;

    public function clearNamespace(string $namespace): void;
}
```

MVP driver:

```text
FileCacheDriver
```

Future drivers MAY include Redis.

Business module tidak boleh mengetahui driver.

---

# 41. Cache Key Standard

Format logical:

```text
{namespace}:{site_id}:{resource}:{identifier}:{variant}
```

Contoh:

```text
content:3:post:891
seo:3:content:891
page:3:/teknologi/ai-baru
archive:3:category:19:page:1
```

Filesystem SHOULD hash logical key.

---

# 42. Full Page Cache Contract

Hanya berlaku untuk:

```text
GET
HEAD
anonymous visitor
public content
cacheable response
```

MUST NOT digunakan untuk:

```text
logged-in admin
preview
draft
POST
frontend submission dashboard
password reset
personalized page
```

---

# 43. Page Cache Response

Page cache SHOULD menyimpan:

```text
HTML body
status code
selected response headers
created_at
dependency list
```

Tidak harus menyimpan PHP serialized response object.

---

# 44. Cache Dependency Contract

Contoh dependency:

```text
content:891
term:19
author:82
site-settings
theme:portal-default
```

Page:

```text
/news/article-x
```

mempunyai dependencies.

Saat:

```text
content:891 updated
```

seluruh cache yang bergantung padanya dapat ditemukan.

---

# 45. Cache Invalidation Contract

Canonical invalidation triggers:

```text
content.updated
content.published
content.deleted

term.updated
term.deleted

media.updated

theme.activated

settings.updated
```

Content publish minimum invalidates:

```text
single URL
homepage
content type archive
attached category archives
attached tag archives
author archive
sitemap
feed
relevant API cache
```

---

# 46. Cache Consistency Guarantee

Requirement produk:

**Perubahan content harus langsung terlihat setelah publish/update.**

Karena itu:

```text
TTL
```

bukan mekanisme utama consistency.

Mutation MUST:

```text
save
→ commit
→ invalidate relevant cache
→ optionally warm critical cache
```

---

# 47. Stampede Protection

Cache regeneration SHOULD menggunakan filesystem lock.

Concept:

```text
cache missing
    ↓
acquire lock
    ↓
generate
    ↓
write cache
    ↓
release lock
```

Request lain MAY menerima stale cache beberapa detik apabila implementasi stale-while-revalidate sederhana tersedia.

Tidak wajib untuk MVP pertama.

---

# 48. HTTP Cache Contract

Public response SHOULD menghasilkan header sesuai kebutuhan:

```text
Cache-Control
ETag
Last-Modified
```

Static assets SHOULD menggunakan long cache lifetime dengan versioned filename.

Contoh:

```text
app.abc123.js
style.def456.css
```

---

# 49. REST API Contract

Version:

```text
/api/v1
```

Version MUST ada dari awal.

Contoh:

```text
GET /api/v1/posts
GET /api/v1/posts/{id}
GET /api/v1/posts/slug/{slug}
GET /api/v1/categories
```

Write:

```text
POST   /api/v1/posts
PATCH  /api/v1/posts/{id}
DELETE /api/v1/posts/{id}
```

---

# 50. Public API Policy

Anonymous API:

```text
READ ONLY
```

Mutation:

```text
authenticated
+
authorized capability
+
CSRF protection jika session based
```

Public API tidak boleh mengembalikan:

```text
password hash
internal file path
private email
reset token
internal debug data
```

---

# 51. API Success Contract

Single resource:

```json
{
  "data": {
    "id": 182,
    "type": "post"
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
    "total": 316,
    "total_pages": 16
  }
}
```

---

# 52. API Error Contract

```json
{
  "error": {
    "code": "CONTENT_NOT_FOUND",
    "message": "Content not found.",
    "details": {}
  }
}
```

HTTP status harus sesuai.

Examples:

```text
400 VALIDATION_ERROR
401 UNAUTHENTICATED
403 FORBIDDEN
404 CONTENT_NOT_FOUND
409 CONFLICT
422 INVALID_STATE_TRANSITION
429 RATE_LIMITED
500 INTERNAL_ERROR
```

---

# 53. Error Code Policy

Error code bersifat machine-readable.

MUST:

```text
UPPER_SNAKE_CASE
```

Agent tidak boleh hanya mengembalikan:

```text
Something went wrong
```

tanpa error code.

---

# 54. Pagination Contract

Input:

```text
?page=1&per_page=20
```

Maximum:

```text
per_page <= 100
```

Default:

```text
20
```

Deep pagination optimization dapat dilakukan kemudian.

---

# 55. Theme Contract

Theme location:

```text
/themes/{theme-name}/
```

Structure:

```text
theme.json
templates/
partials/
assets/
functions.php
```

Theme MUST memiliki manifest.

---

# 56. Theme Manifest

Contoh:

```json
{
  "name": "Portal Default",
  "slug": "portal-default",
  "version": "1.0.0",
  "requires_core": ">=1.0.0",
  "supports": [
    "menus",
    "featured-media",
    "comments"
  ]
}
```

---

# 57. Template Resolution Contract

Priority:

```text
single-{content-type}.php
single.php
index.php
```

Archive:

```text
archive-{content-type}.php
archive.php
index.php
```

Taxonomy:

```text
taxonomy-{taxonomy}.php
taxonomy.php
archive.php
index.php
```

Additional:

```text
home.php
page.php
author.php
search.php
404.php
```

---

# 58. Theme Data Rule

Template menerima prepared ViewModel.

Contoh:

```php
$template->render('single', [
    'content' => $contentView,
    'seo' => $seoView,
    'related' => $relatedView,
]);
```

Theme MUST NOT melakukan:

```php
$db->query(...)
```

---

# 59. Plugin Contract

Plugin location:

```text
/plugins/{plugin-slug}/
```

Required:

```text
plugin.json
Plugin.php
```

---

# 60. Plugin Manifest

```json
{
  "name": "Example Plugin",
  "slug": "example-plugin",
  "version": "1.0.0",
  "requires_core": ">=1.0.0",
  "bootstrap": "Plugin.php"
}
```

Optional:

```text
migrations
permissions
blocks
routes
content_types
taxonomies
```

---

# 61. Plugin Lifecycle

Canonical lifecycle:

```text
discover
install
activate
bootstrap
deactivate
uninstall
```

`uninstall` MUST NOT otomatis menghapus data penting kecuali plugin explicitly defines cleanup behavior dan admin mengkonfirmasi.

---

# 62. Plugin API

Minimal stable API:

```php
add_action()
add_filter()

register_block()
register_content_type()
register_taxonomy()

register_route()
register_capability()
register_setting()
```

Plugin SHOULD menggunakan service container untuk dependency.

---

# 63. Plugin Isolation Rules

Plugin MUST NOT:

- modify core files;
- patch vendor/core code;
- query undocumented core table structure;
- assume Site ID;
- create globally named functions tanpa namespace/prefix;
- output HTML pada random lifecycle event.

---

# 64. Hooks Contract

Actions:

```php
do_action('content.published', $content);
```

Filters:

```php
$title = apply_filters(
    'seo.title',
    $title,
    $content
);
```

Filter MUST return compatible datatype.

---

# 65. Plugin Failure Policy

Plugin error SHOULD:

- be logged;
- identify plugin;
- avoid bringing entire public site down where possible.

Boot-critical failures MAY deactivate plugin after safe detection, but automatic deactivation is optional for v1.

---

# 66. Multisite Contract

Site table minimum:

```text
sites
-----
id
name
status
primary_domain
timezone
active_theme
created_at
updated_at
```

Domains:

```text
site_domains
------------
id
site_id
domain
is_primary
created_at
```

Unique index:

```text
domain
```

---

# 67. Domain Resolver

Request:

```text
Host: news.example.com
```

Flow:

```text
normalize host
↓
domain cache lookup
↓
site_domains
↓
SiteContext
```

Host input MUST be validated before use.

---

# 68. Site Settings Contract

Settings should avoid one giant unserialized global blob.

Suggested:

```text
site_settings
-------------
site_id
namespace
setting_key
setting_value
autoload
```

Frequently-used settings MAY be compiled into single site configuration cache file.

---

# 69. Settings API

```php
interface SettingsInterface
{
    public function get(
        string $namespace,
        string $key,
        mixed $default = null
    ): mixed;

    public function set(
        string $namespace,
        string $key,
        mixed $value
    ): void;
}
```

Do not query settings table repeatedly during request.

Load autoload settings once and cache.

---

# 70. Database Baseline Schema

Core tables:

```text
sites
site_domains
site_settings

users
roles
capabilities
role_capabilities
user_roles
password_resets

contents
content_revisions

taxonomies
terms
content_terms

content_seo

media
media_variants

comments

workflow_logs

redirects

notifications

plugins
site_plugins

themes

ads
ad_rules

migrations
```

Exact migration files menjadi tanggung jawab Database/Core agents berdasarkan contract ini.

---

# 71. Contents Table Baseline

Recommended logical fields:

```text
id
site_id
type
author_id

title
slug
excerpt

content_json
content_html
content_plain

status

featured_media_id

comment_status

published_at
scheduled_at

created_at
updated_at
```

Recommended indexes:

```text
(site_id, type, status, published_at)

(site_id, type, slug)

(site_id, author_id, status)

(site_id, status, scheduled_at)
```

Unique:

```text
(site_id, type, slug)
```

untuk public content type bila applicable.

---

# 72. Query Rules

MUST NOT:

```text
SELECT *
```

pada high-traffic query jika hanya beberapa kolom diperlukan.

MUST NOT melakukan query per row dalam loop.

Example wrong:

```text
get 20 posts
for every post:
    get author
    get category
    get image
```

Preferred:

```text
batch fetch authors
batch fetch terms
batch fetch media
```

atau join yang terukur.

---

# 73. Query Budget

Performance baseline:

### Cached public page

```text
0 SQL query
```

ideal path.

### Uncached article

```text
<= 5 logical queries
```

### Archive

```text
<= 5 logical queries
```

### API article collection

Target:

```text
<= 6 logical queries
```

tergantung relation.

---

# 74. Migration System

Migration naming:

```text
YYYYMMDDHHMMSS_description.php
```

Migration MUST mempunyai:

```text
up()
down()
```

jika rollback technically safe.

Core dan plugin migration histories dipisahkan secara logical.

---

# 75. Migration Locking

Sistem MUST mencegah dua migration berjalan bersamaan.

Filesystem/database lock dapat digunakan.

---

# 76. Editorial State Machine

Allowed transitions:

```text
draft
→ submitted

submitted
→ review
→ revision_requested
→ approved

revision_requested
→ submitted

approved
→ scheduled
→ published

scheduled
→ published

published
→ archived
```

Admin capability MAY support exceptional transitions.

Setiap exceptional transition harus masuk audit log.

---

# 77. Workflow Service Contract

```php
interface EditorialWorkflowInterface
{
    public function transition(
        int $contentId,
        string $targetStatus,
        ActorInterface $actor,
        ?string $note = null
    ): Content;
}
```

Workflow service bertanggung jawab:

- validating transition;
- permission;
- audit log;
- event dispatch.

---

# 78. Scheduler Contract

Scheduled publish:

```text
status = scheduled
scheduled_at <= now()
```

Cron endpoint/process harus:

1. acquire lock;
2. fetch batch;
3. publish each via ContentService/Workflow;
4. release lock.

MUST NOT mengubah DB secara langsung.

---

# 79. Cron Architecture

Entry:

```text
/bin/cron.php
```

Jobs:

```text
publish_scheduled_content
process_notifications
cleanup_temp
cleanup_logs
cleanup_expired_sessions
```

Web fallback MAY execute jobs probabilistically dengan strict time limit.

---

# 80. Notifications Contract

Notification event:

```text
content_submitted
revision_requested
content_approved
content_scheduled
content_published
```

Channels:

```text
internal
email
```

Settings:

```text
notifications.{event}.{channel}
```

---

# 81. Email Delivery

MVP SHOULD support PHP mail-compatible adapter.

Architecture:

```php
interface MailerInterface
{
    public function send(Message $message): void;
}
```

Future SMTP adapter dapat ditambahkan.

Core business logic tidak boleh memanggil `mail()` langsung.

---

# 82. Comments Contract

Comment status:

```text
pending
approved
spam
trash
```

Fields minimum:

```text
id
site_id
content_id
parent_id
user_id
author_name
author_email
body
status
created_at
```

Jika authenticated user:

```text
user_id
```

diprioritaskan.

---

# 83. Advertising Contract

Ad placement registered by slug.

Examples:

```text
header
sidebar
article_before
article_middle
article_after
sticky_mobile
footer
```

Advertising service:

```php
interface AdvertisingServiceInterface
{
    public function resolve(
        string $placement,
        AdContext $context
    ): ?Ad;
}
```

Theme tidak menentukan targeting sendiri.

---

# 84. Analytics Integration Contract

Integration service produces configured snippets.

Theme calls:

```text
render_head_integrations()
render_body_open_integrations()
render_footer_integrations()
```

Supported initial:

```text
Google Analytics
Matomo
```

---

# 85. Redirect Contract

Lookup:

```text
normalized request path
↓
indexed exact match
```

No table-wide regex by default.

Fields:

```text
site_id
source_path
target_url
status_code
hit_count
```

Supported:

```text
301
302
```

---

# 86. URL Generation Contract

All internal URLs MUST pass UrlGenerator.

```php
interface UrlGeneratorInterface
{
    public function content(Content $content): string;

    public function taxonomy(
        string $taxonomy,
        Term $term
    ): string;

    public function route(
        string $name,
        array $params = []
    ): string;
}
```

Do not concatenate domain manually.

Critical for custom domain/multisite.

---

# 87. Permalink Contract

Permalink pattern configurable per site.

Initial patterns MAY include:

```text
/{slug}
/{category}/{slug}
/{year}/{month}/{slug}
```

Canonical generator must resolve collisions safely.

---

# 88. Security Contract

Every mutation path MUST validate:

```text
authentication
authorization
CSRF when applicable
input validation
business rule
```

Order:

```text
authenticate
↓
authorize
↓
validate
↓
execute
```

---

# 89. Input Validation

Controllers translate raw input into request DTO.

Example:

```text
HTTP request
↓
UpdateContentRequest
↓
validation
↓
UpdateContentData
↓
ContentService
```

Service MUST NOT rely solely on frontend validation.

---

# 90. Output Escaping

Rule:

```text
sanitize on input where necessary
escape on output
```

Different escaping contexts:

```text
HTML
attribute
URL
JavaScript
JSON
```

Raw HTML block must receive stricter sanitization and capability restrictions.

---

# 91. CSRF

Session-authenticated mutation requests MUST require CSRF token.

REST APIs using future token authentication MAY use a different protection model.

---

# 92. Session Contract

Session:

- HTTP only cookie;
- secure cookie when HTTPS;
- SameSite configuration;
- session regeneration after login;
- session invalidation after logout/password reset if feasible.

---

# 93. Password Reset

Reset tokens MUST:

- be random;
- expire;
- be single use;
- never be stored plaintext if avoidable.

---

# 94. Logging Contract

Logger interface:

```php
interface LoggerInterface
{
    public function debug(string $message, array $context = []): void;
    public function info(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
}
```

Production default:

```text
DEBUG OFF
```

---

# 95. Structured Log Fields

Where applicable:

```text
request_id
site_id
user_id
module
event
exception
```

MUST NOT log:

```text
password
reset token
session token
database password
secret key
```

---

# 96. Request ID

Setiap HTTP request SHOULD mempunyai request ID.

Example:

```text
req_a8e81...
```

Digunakan untuk debugging.

Tidak perlu UUID jika expensive dependency tidak diinginkan.

---

# 97. Error Handling

Production error response:

```text
generic message
request_id
```

Detailed:

```text
stack trace
file path
SQL
```

hanya masuk log.

---

# 98. Configuration Contract

Environment configuration:

```text
database credentials
app environment
debug
base filesystem paths
secret
```

Site configuration:

```text
site name
timezone
theme
SEO defaults
notification settings
analytics
```

Keduanya MUST dipisahkan.

---

# 99. Configuration Access

Tidak boleh tersebar:

```php
getenv(...)
```

di banyak module.

Gunakan:

```php
ConfigInterface
```

Contoh:

```php
$config->get('database.host');
```

---

# 100. Service Container

Core MAY menyediakan lightweight dependency container.

Requirements:

- constructor injection preferred;
- no reflection-heavy container required;
- lazy service loading where useful;
- explicit service registration acceptable.

---

# 101. Bootstrap Performance

Bootstrap public cached request harus sesedikit mungkin.

Ideal:

```text
index.php
↓
minimal config
↓
site resolver
↓
page cache
↓
response
```

Jangan bootstrap seluruh:

```text
plugin system
database ORM
admin services
block editor
SEO services
```

sebelum mengetahui bahwa cache miss.

---

# 102. Fast Cache Path

Target path:

```text
Apache
 ↓
public/index.php
 ↓
SiteResolver
 ↓
PageCacheLookup
 ↓
HIT
 ↓
send response
 ↓
exit
```

Database MAY be avoided entirely jika domain map juga tersedia pada generated file cache.

---

# 103. Domain Map Cache

Untuk menghindari query site pada setiap request:

```text
/storage/cache/system/domain-map.php
```

Example compiled data:

```php
return [
    'site-a.com' => 1,
    'news.site-a.com' => 2,
];
```

Regenerated on domain changes.

---

# 104. Autoload Strategy

Composer MAY be used for PSR-4 autoloading.

Production MUST include vendor output, sehingga shared hosting tidak perlu menjalankan Composer.

Avoid large framework dependencies.

---

# 105. Coding Standard

Recommended:

```text
PSR-4
PSR-12
strict_types where practical
namespaces mandatory
type declarations preferred
```

Global functions hanya diperbolehkan untuk carefully designed plugin helper API jika diperlukan.

---

# 106. PHP Compatibility

Project harus menetapkan minimum.

Recommended initial baseline:

```text
PHP >= 8.2
```

Jika market shared hosting memerlukan PHP 8.1, baseline dapat diturunkan sebelum implementation dimulai.

Seluruh agent MUST memakai baseline yang sama.

---

# 107. Database Compatibility

Supported:

```text
MySQL
MariaDB
```

Queries MUST avoid vendor-specific feature yang tidak diperlukan.

FULLTEXT capability detection MAY digunakan.

---

# 108. Admin Architecture

Admin UI terpisah secara presentation layer.

Options:

```text
Vue SPA
React SPA
lightweight vanilla JS
```

Recommended:

```text
Vue + Vite
```

karena output dapat di-build menjadi static JS/CSS dan admin cukup complex untuk component model.

Ini bukan runtime requirement.

---

# 109. Admin API

Admin SHOULD menggunakan application API/service endpoints.

Admin tidak boleh memiliki alternate business rules.

Example:

```text
Admin Edit
   ↓
API/Controller
   ↓
ContentService.update()
```

Frontend submission:

```text
Contributor Dashboard
   ↓
Controller/API
   ↓
ContentService.update()
```

Same service.

---

# 110. Frontend Rendering

Frontend default menggunakan server-rendered PHP templates + full-page cache.

Tidak menggunakan client-side rendering sebagai requirement.

Ini memberikan:

- SEO native;
- minimal JS;
- faster first render;
- kompatibilitas shared hosting.

---

# 111. WordPress Migration Boundary

Migration module MAY membaca schema WordPress langsung.

Tetapi setelah dibaca:

```text
WordPress data
↓
Mapper
↓
CMS DTO
↓
CMS Service
```

Importer MUST NOT menulis langsung ke target core table kecuali bulk-specific import adapter telah disetujui dan menjaga invariant.

---

# 112. Import Idempotency

Migration SHOULD dapat dilanjutkan.

Store mapping:

```text
source_type
source_id
target_id
status
```

Sehingga interrupted migration tidak menggandakan content.

---

# 113. Import Logging

For each unsupported field:

```text
WARN
```

Contoh:

```text
Yoast field _wpseo_x not mapped
post ID 128
```

Tidak boleh silent discard untuk metadata penting.

---

# 114. Database Naming Convention

Table:

```text
snake_case
plural
```

Columns:

```text
snake_case
```

Foreign key:

```text
{entity}_id
```

Boolean:

```text
is_active
is_primary
```

Datetime:

```text
created_at
updated_at
published_at
```

---

# 115. Index Contract

Agent yang membuat migration MUST mendokumentasikan:

```text
expected query
index supporting query
```

Contoh:

```sql
WHERE site_id = ?
AND status = 'published'
ORDER BY published_at DESC
```

requires:

```text
(site_id, status, published_at)
```

---

# 116. Database Foreign Keys

Foreign key MAY digunakan bila kompatibel dengan shared hosting/database engine.

Application MUST tetap menjaga data integrity.

Delete policies harus eksplisit:

```text
CASCADE
SET NULL
RESTRICT
```

jangan bergantung pada default.

---

# 117. Soft Delete Policy

Tidak semua table membutuhkan soft delete.

Suggested:

```text
Content → status trash
Users → status disabled
Comments → status trash
```

Hard deletion dilakukan oleh explicit purge action.

---

# 118. Performance Instrumentation

Development SHOULD memiliki:

```text
query count
query time
memory usage
request duration
cache hit/miss
```

Tidak harus aktif production.

---

# 119. Query Regression Testing

Integration test dapat mengassert:

```text
single article <= N queries
```

Agent tidak boleh menambahkan feature yang menghasilkan N+1 tanpa alasan.

---

# 120. Contract Testing

Setiap shared interface MUST mempunyai contract tests jika memiliki multiple implementations.

Example:

```text
CacheInterfaceTestSuite
```

harus bisa dijalankan terhadap:

```text
ArrayCache
FileCache
future RedisCache
```

---

# 121. Agent Development Rules

Setiap agent menerima:

```text
ownership
dependencies
allowed files
forbidden files
contracts
deliverables
acceptance criteria
tests
```

Agent MUST NOT mengubah contract global hanya agar task-nya mudah.

---

# 122. Core Agent Ownership

Core Agent owns:

```text
Bootstrap
Router
HTTP kernel
Configuration
Container
Events
Database abstraction
Migrations
Filesystem
Logging
Common exceptions
```

Must not implement:

```text
SEO
Content business rules
Ads
Editorial rules
```

---

# 123. Multisite Agent Ownership

Owns:

```text
Site entity
Domain mapping
Site resolver
SiteContext
Site settings
Tenant isolation helpers
```

Must provide contract before most data agents finalize repository implementation.

---

# 124. Identity Agent Ownership

Owns:

```text
Authentication
Users
Sessions
Roles
Capabilities
Password reset
Actor implementation
```

---

# 125. Content Agent Ownership

Owns:

```text
Content entity
Content repository
Content types
Slug
Content service
Base archive/query
```

Does not own:

```text
SEO
Cache
Workflow notification
```

It emits events instead.

---

# 126. Cache Agent Ownership

Owns:

```text
CacheInterface implementation
Cache keys
File cache
Page cache
Dependency graph
Invalidation
Cache middleware
```

MUST consume events rather than changing ContentService internals where possible.

---

# 127. SEO Agent Ownership

Owns:

```text
SEO metadata
Schema
Sitemap
Canonical
Robots
OpenGraph
Redirect manager
Breadcrumb
SEO analysis
```

---

# 128. Media Agent Ownership

Owns:

```text
Upload
Media repository
Variants
Image processing
WebP
EXIF removal
Responsive media helpers
```

---

# 129. Workflow Agent Ownership

Owns:

```text
State machine
Transition validation
Workflow log
Scheduling rules
Review process
```

---

# 130. Block Agent Ownership

Owns:

```text
Block schema
Registry
Sanitization
Renderer
Core blocks
Block migrations
Editor contract
```

---

# 131. Plugin Agent Ownership

Owns:

```text
Discovery
Manifest
Activation
Hooks
Filters
Lifecycle
Plugin migration integration
```

---

# 132. Theme Agent Ownership

Owns:

```text
Theme registry
Manifest
Template resolver
View renderer
Menus
Theme assets
```

---

# 133. API Agent Ownership

Owns:

```text
/v1 controllers
serialization
pagination
API errors
request validation adapter
```

Does not implement duplicate domain logic.

---

# 134. Integration Between Agents

Example content publish:

```text
API Agent
    ↓
Workflow Agent
    ↓
Content Agent
    ↓
DB
    ↓
event: content.published
    ↓
 ┌────────────┬────────────┬─────────────┐
 ↓            ↓            ↓             ↓
Cache        SEO      Notification     Plugin
Agent        Agent         Agent        hooks
```

Tidak ada direct dependency:

```text
Workflow → Cache concrete implementation
```

---

# 135. Shared Contract Freeze

Sebelum parallel implementation besar dimulai, berikut MUST dianggap frozen v1:

```text
SiteContextInterface

ActorInterface

Content entity fields

ContentRepositoryInterface

ContentServiceInterface

Event naming convention

CacheInterface

API response envelope

Block base schema

Plugin manifest schema

Theme manifest schema

Error response schema
```

---

# 136. Contract Versioning

Application contract version:

```text
CORE_CONTRACT_VERSION = 1
```

Plugin manifest MAY declare:

```json
{
  "requires_contract": "^1"
}
```

---

# 137. Breaking Change Definition

Breaking jika:

- interface method dihapus;
- method parameter berubah;
- return type berubah;
- event payload required field dihapus;
- error response shape berubah;
- block storage shape berubah;
- plugin manifest required field berubah.

---

# 138. Definition of Done for Every Agent

Task agent tidak dianggap selesai sampai:

1. code implemented;
2. unit tests pass;
3. relevant integration tests pass;
4. no forbidden dependency introduced;
5. documented public contract;
6. migration supplied if database changes;
7. cache behavior considered;
8. multisite behavior tested;
9. error behavior tested;
10. security considerations addressed.

---

# 139. Required Cross-Cutting Tests

Every site-specific module MUST test:

```text
Site A cannot read Site B data.
```

Every mutation MUST test:

```text
unauthenticated denied
unauthorized denied
authorized allowed
```

Every high-traffic public feature SHOULD test query count.

---

# 140. Core Acceptance Test — Cache

Given:

```text
published article
```

First request:

```text
cache miss
DB queried
page rendered
cache stored
```

Second request:

```text
cache hit
0 content DB query
same HTML returned
```

---

# 141. Core Acceptance Test — Update

Given cached article.

When editor updates article.

Then:

```text
database updated
revision recorded
content.updated emitted
relevant cache invalidated
next public request returns new content
```

No stale article permitted after successful update.

---

# 142. Core Acceptance Test — Multisite

Given:

```text
site A content ID 100
site B content ID 101
```

Site A API MUST NOT access Site B content even by manually providing ID 101.

Expected:

```text
404
```

rather than revealing existence via `403`.

---

# 143. Core Acceptance Test — Plugin

Plugin registers:

```text
custom content type
custom taxonomy
custom block
action listener
filter
```

All work without modifying core source.

Plugin can deactivate cleanly.

---

# 144. Core Acceptance Test — WordPress Import

Input WordPress:

```text
post
author
category
tag
featured image
Yoast metadata
comments
slug
publish date
```

Output CMS must preserve semantically equivalent data.

Old permalink either:

```text
preserved
```

or:

```text
301 redirect created
```

---

# 145. Deployment Architecture

Recommended:

```text
/home/account/
    cms/
        app/
        plugins/
        themes/
        storage/
        config/
        vendor/

    public_html/
        index.php
        assets/
        uploads/
```

If shared hosting forces entire project under public_html, sensitive directories MUST be protected using Apache configuration.

---

# 146. Web-Accessible Rules

Only these SHOULD be public:

```text
public/index.php
public/assets/
public/uploads/
```

These MUST NOT be directly web-readable:

```text
config
storage/logs
database
app
vendor internal metadata
.env
```

---

# 147. Shared Hosting Deployment

Release package MUST include:

```text
PHP source
vendor dependencies
compiled admin assets
compiled theme assets where applicable
migration scripts
```

User should not need to run:

```text
npm install
composer install
```

on production hosting.

---

# 148. Update Process

Manual deployment:

```text
backup externally if desired
upload files via FTP/File Manager
run migration endpoint/script with admin authorization
clear necessary compiled caches
finish
```

No automatic updater in v1.

---

# 149. Maintenance Mode

Migration deployment SHOULD allow lightweight maintenance mode.

Config:

```text
maintenance = true
```

Admin MAY bypass with session/token.

---

# 150. Suggested Build Phases

## Phase 0 — Architecture Contract

Build:

```text
interfaces
entities
event names
folder structure
database baseline
test bootstrap
```

No feature-heavy UI.

---

## Phase 1 — Foundation

Agents:

```text
Core
Multisite
Identity
```

Deliver:

```text
routing
database
SiteContext
login
RBAC
migration runner
```

---

## Phase 2 — Publishing Core

Agents:

```text
Content
Taxonomy
Media
Revision
Workflow
```

Deliver:

```text
create/edit/review/publish article
```

---

## Phase 3 — Performance

Agent:

```text
Cache
```

Deliver:

```text
object cache
page cache
event invalidation
dependency graph
query metrics
```

Performance architecture harus stabil sebelum terlalu banyak fitur tambahan.

---

## Phase 4 — Presentation

Agents:

```text
Block
Theme
SEO
Search
```

---

## Phase 5 — Extensibility

Agents:

```text
Plugin
API
Frontend Submission
Comments
Ads
Notifications
```

---

## Phase 6 — Migration & Hardening

Agents:

```text
WordPress Migration
Security Review
Performance Review
Integration Tests
```

---

# 151. Recommended First Repository Milestone

Repository pertama sebaiknya belum memiliki seluruh fitur.

Milestone:

```text
GET /
↓
SiteResolver
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
HTML
```

plus:

```text
EventDispatcher
FileCache
Authentication skeleton
Migration runner
Test framework
```

Jika foundation ini bersih, agent berikutnya dapat bekerja jauh lebih aman.

---

# 152. Architecture Decision Records

Keputusan besar SHOULD dicatat dalam:

```text
/docs/adr/
```

Contoh:

```text
ADR-001-modular-monolith.md
ADR-002-file-cache.md
ADR-003-site-isolation.md
ADR-004-block-storage-json.md
ADR-005-no-production-node-runtime.md
```

Format:

```text
Context
Decision
Consequences
Alternatives
```

Agent tidak boleh membatalkan keputusan ADR diam-diam.

---

# 153. Initial ADR Decisions

### ADR-001
Use Modular Monolith.

Reason:

```text
shared hosting
operational simplicity
performance
agent boundaries can still be modular
```

### ADR-002
Use lightweight PHP core, not Laravel.

Reason:

```text
minimal bootstrap
resource control
shared-host compatibility
```

### ADR-003
Use filesystem caching as default distributed-independent cache.

### ADR-004
Store block source as JSON + compiled HTML.

### ADR-005
Multisite shares codebase/database while all content data is site-scoped.

### ADR-006
Public frontend uses server rendering + full-page cache.

### ADR-007
Plugin architecture uses custom API, not WordPress compatibility.

---

# 154. Agent Merge Policy

Each agent SHOULD submit isolated changes.

Pull requests MUST state:

```text
Module:
Contracts changed:
Database migrations:
Events emitted:
Events consumed:
Cache impact:
API impact:
Multisite impact:
Tests:
```

Cross-module code changes require explanation.

---

# 155. Conflict Resolution Rule

Jika dua agents membutuhkan perubahan contract yang sama:

```text
STOP feature-level merge
↓
resolve architecture contract
↓
version/update interface
↓
resume implementation
```

Jangan membuat dua interfaces berbeda untuk masalah yang sama hanya untuk menghindari koordinasi.

---

# 156. Forbidden Shortcuts

Agents MUST NOT:

```text
hardcode site_id = 1

query database from theme

query database from block renderer

create new PDO connection inside module

store every feature field in generic meta table

clear all cache for every content edit

copy content business logic into API controller

copy workflow rules into frontend JavaScript

store user password using custom hashing

trust client-side permission checks

directly edit another module's table without contract

depend on Node.js production runtime

introduce Redis requirement

introduce Laravel/Symfony framework wholesale

silently change API response shape
```

---

# 157. Performance Anti-Patterns

Forbidden unless justified:

```text
N+1 queries
COUNT(*) on huge tables per page request
SELECT all options/settings every request
loading every plugin before page-cache check
parsing block JSON every cached request
building sitemap per request
generating thumbnails during page view
regex redirect scan across entire database
clearing entire filesystem cache after article edit
```

---

# 158. Shared Hosting Resource Strategy

CPU:

```text
reduce PHP execution through page caching
```

Memory:

```text
lazy load services
avoid giant framework container
avoid loading admin code publicly
```

Database:

```text
indexes
compiled content
batch relations
precomputed counters
file caching
```

Disk:

```text
cache cleanup
image variants controlled
log rotation
revision max 3
```

---

# 159. Product Technical North Star

Public content serving should progressively approach:

```text
REQUEST
   ↓
HOST LOOKUP CACHE
   ↓
PAGE CACHE
   ↓
SEND HTML
```

instead of:

```text
REQUEST
   ↓
FULL CMS BOOT
   ↓
DATABASE
   ↓
CONTENT
   ↓
META
   ↓
TAXONOMY
   ↓
PLUGIN QUERIES
   ↓
RENDER
```

---

# 160. Architecture Completion Criteria

Technical architecture dapat dianggap siap untuk implementation paralel ketika:

- folder/module boundaries telah dibuat;
- shared interfaces berada di repository;
- database baseline migrations tersedia;
- SiteContext bekerja;
- EventDispatcher bekerja;
- CacheInterface bekerja;
- error format dibekukan;
- Content entity dibekukan;
- API envelope dibekukan;
- plugin manifest dibekukan;
- block base format dibekukan;
- CI/test command tersedia;
- coding standards tersedia;
- ADR awal tersedia.

Setelah titik ini, feature agents dapat mulai bekerja secara paralel.

---

# 161. Recommended Immediate Next Deliverables

Dokumen ini sebaiknya diikuti oleh empat artifact teknis:

### A. Database Schema & ERD Specification

Berisi:

```text
seluruh tabel
columns
datatype
foreign keys
indexes
unique constraints
relationship
query rationale
```

### B. API Specification

Berisi seluruh endpoint:

```text
request
response
permission
validation
error codes
pagination
```

### C. Agent Task Pack

Satu task specification untuk setiap agent, misalnya:

```text
Agent 01 Core
Agent 02 Multisite
Agent 03 Identity
Agent 04 Content
...
```

Setiap file dapat diberikan langsung kepada coding agent.

### D. Repository Bootstrap Specification

Berisi file apa saja yang harus dibuat sebelum agent paralel mulai bekerja.

---

# 162. Final Architecture Principle

CMS ini harus memiliki fleksibilitas yang biasanya dicari pengguna WordPress tanpa membawa pola arsitektur yang menyebabkan sistem menjadi berat.

Extensibility dicapai melalui:

```text
interfaces
events
registries
plugins
themes
blocks
```

bukan dengan membuat setiap data menjadi generic metadata.

Performance dicapai melalui:

```text
cache-first requests
compiled content
targeted invalidation
indexed queries
site-scoped repositories
minimal bootstrap
```

bukan melalui penambahan server kompleks.

Dan parallel agent development dicapai melalui:

```text
clear module ownership
stable contracts
event-driven integration
contract tests
migration discipline
```

bukan dengan membiarkan setiap agent mengubah seluruh codebase.