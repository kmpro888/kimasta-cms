# Product Requirements Document
## Lightweight Headless CMS untuk Portal Media

**Status:** Draft v1.0  
**Target:** Production-ready MVP  
**Platform utama:** Shared Hosting standar  
**Runtime production minimum:** PHP 8.x + MySQL/MariaDB + Apache  
**Node.js di production:** Tidak wajib  
**Arsitektur:** Lightweight PHP Core + API + separated frontend + file/static cache  
**Use case utama:** Portal berita/media dengan banyak contributor, author, reviewer, dan editor

---

# 1. Product Vision

Membangun CMS khusus portal media yang mengambil fleksibilitas terbaik dari WordPress tetapi menghilangkan kelemahan utamanya, terutama konsumsi database yang tinggi akibat query berulang, struktur metadata generik, plugin overhead, dan rendering halaman dinamis pada setiap request.

CMS harus:

1. Ringan di shared hosting.
2. Database-efficient by design.
3. Memiliki frontend dan backend yang terpisah.
4. Mendukung banyak author/contributor.
5. Mendukung editorial workflow.
6. Memiliki SEO built-in.
7. Memiliki plugin/module system.
8. Memiliki theme system.
9. Mendukung multisite.
10. Memiliki block editor.
11. Mendukung frontend submission.
12. Mengutamakan full-page caching.
13. Tidak membutuhkan Redis, Memcached, Node.js server, Elasticsearch, atau daemon lain.

Target workload awal:

- ±30 artikel baru/hari.
- ±3.000 visitor/hari.
- Shared hosting ±1 GB RAM.
- Apache + PHP + MySQL/MariaDB.
- Storage tersedia ±60 GB.

Arsitektur tetap harus mampu berkembang jauh di atas angka tersebut tanpa redesign fundamental.

---

# 2. Product Principles

## 2.1 Database Is Not the Page Cache

Request visitor tidak boleh selalu menghasilkan query database.

Untuk konten publik yang sudah ter-cache:

```text
Visitor
   ↓
Apache
   ↓
Full Page Cache
   ↓
HTML
```

Database hanya diakses ketika:

- cache belum ada;
- cache telah di-invalidasi;
- request membutuhkan data user;
- halaman memang bersifat dinamis;
- admin sedang menggunakan backend.

---

## 2.2 Hindari Model WordPress `postmeta` untuk Semua Data

Data penting harus mempunyai kolom/tabel yang jelas.

Contoh:

```text
contents
--------
id
site_id
type
title
slug
excerpt
content_json
content_html
content_plain
status
author_id
featured_image_id
published_at
created_at
updated_at
```

SEO tidak disimpan sebagai puluhan row generic metadata.

Contoh:

```text
content_seo
-----------
content_id
meta_title
meta_description
canonical_url
robots_index
robots_follow
og_title
og_description
og_image_id
schema_type
```

Tujuannya adalah mengurangi query dan JOIN yang tidak perlu.

---

# 3. User Roles

CMS memiliki role default:

### Administrator
Kontrol penuh terhadap site.

### Editor
Mengelola seluruh konten dan proses editorial.

### Reviewer
Review artikel, approve, atau meminta revisi.

### Author
Membuat, mengedit, dan publish sesuai permission yang diberikan.

### Contributor
Membuat dan submit artikel tetapi tidak publish secara langsung.

### User / Subscriber
Memiliki akun dan dapat diberikan akses frontend submission sesuai konfigurasi.

Permission tidak boleh hard-coded berdasarkan nama role.

Sistem harus menggunakan capability-based authorization.

Contoh:

```text
post.create
post.edit_own
post.edit_all
post.submit
post.review
post.publish
post.delete
comment.moderate
media.upload
user.manage
plugin.manage
site.manage
```

Plugin dapat mendaftarkan capability tambahan.

---

# 4. Authentication

Authentication MVP:

- username + password;
- email + password;
- forgot/reset password;
- session authentication untuk backend;
- CSRF protection;
- secure password hashing dengan `password_hash()`;
- login throttling sederhana.

Tidak termasuk MVP:

- 2FA;
- OAuth login;
- social login.

---

# 5. Content Architecture

CMS harus mendukung:

```text
Post
Page
Custom Post Type
```

Default taxonomy:

```text
Category
Tag
```

Plugin dapat mendaftarkan Custom Taxonomy.

Contoh Custom Post Type:

```text
News
Video
Review
Gallery
Event
Press Release
```

Core harus menyediakan registry:

```php
register_content_type(...)
register_taxonomy(...)
```

---

# 6. Block Editor

CMS tidak akan meniru Elementor secara penuh pada versi pertama.

Editor menggunakan block-based content model.

Blok dasar:

```text
Paragraph
Heading
Image
Gallery
Quote
List
Table
Button
Embed
Video
Code
HTML
Columns
Section
Hero
CTA
FAQ
Accordion
Related Posts
Ad Slot
```

Konten disimpan dalam struktur JSON.

Contoh:

```json
[
  {
    "type": "heading",
    "level": 2,
    "text": "Judul Bagian"
  },
  {
    "type": "paragraph",
    "text": "Konten artikel."
  },
  {
    "type": "image",
    "media_id": 441
  }
]
```

Renderer mengubah struktur tersebut menjadi HTML.

Untuk performa, HTML hasil render juga dapat disimpan sebagai compiled content:

```text
content_json
      ↓
Block Renderer
      ↓
content_html
```

Frontend tidak perlu menjalankan parser block berulang kali apabila konten belum berubah.

Plugin dapat mendaftarkan custom block.

---

# 7. Editorial Workflow

Workflow:

```text
Draft
  ↓
Submitted
  ↓
Review
  ├──→ Revision Requested
  │        ↓
  │     Submitted
  │
  ↓
Approved
  ↓
Scheduled
  ↓
Published
  ↓
Archived
```

Setiap transition mempunyai permission.

CMS menyimpan:

- user yang melakukan transition;
- waktu;
- status sebelum;
- status sesudah;
- catatan reviewer jika tersedia.

---

# 8. Frontend Submission

User wajib login.

User mempunyai frontend dashboard untuk:

- membuat artikel;
- menyimpan draft;
- mengedit draft sendiri;
- upload media;
- submit artikel;
- melihat status;
- melihat permintaan revisi;
- submit ulang setelah revisi;
- melihat artikel published miliknya.

Frontend submission menggunakan service yang sama dengan backend admin.

Tidak boleh terdapat implementasi business logic kedua khusus frontend.

---

# 9. Revision System

Setiap content mempunyai maksimal:

**3 revision snapshots.**

Ketika revision ke-4 dibuat:

```text
Revision 1 → delete
Revision 2 → becomes oldest
Revision 3
Revision 4 → new
```

Autosave tersedia tetapi tidak boleh menghasilkan revision permanen pada setiap autosave.

Autosave menggunakan satu transient draft/autosave record per content/user.

---

# 10. SEO Engine

SEO merupakan **core module**, bukan plugin wajib.

Fitur:

- SEO title;
- meta description;
- canonical URL;
- robots index/noindex;
- follow/nofollow;
- Open Graph;
- Twitter/X card metadata;
- breadcrumb;
- schema.org;
- XML sitemap;
- sitemap index;
- redirects 301;
- redirects 302;
- permalink management;
- slug editor;
- SEO preview;
- basic SEO analysis.

Schema default:

```text
Article
NewsArticle
WebPage
Organization
Person
BreadcrumbList
WebSite
```

Plugin dapat menambahkan schema.

---

# 11. Redirect Manager

Redirect disimpan secara efisien:

```text
redirects
---------
site_id
source_path
target_url
status_code
hits
created_at
```

`source_path` harus mempunyai index.

Redirect lookup tidak boleh melakukan wildcard scan pada seluruh tabel.

---

# 12. Sitemap

Sitemap dibuat dan di-cache.

Contoh:

```text
/sitemap.xml
/sitemap-posts-1.xml
/sitemap-pages-1.xml
/sitemap-categories.xml
```

Sitemap diperbarui hanya ketika content yang relevan berubah.

Tidak dibuat ulang setiap kali visitor membuka sitemap.

---

# 13. Media Library

Media merupakan core feature.

Ketika gambar di-upload:

```text
Original
   ↓
Validate
   ↓
Strip EXIF
   ↓
Compress
   ↓
Generate sizes
   ↓
Generate WebP
   ↓
Save metadata
```

Ukuran default dapat berupa:

```text
thumbnail
small
medium
large
original
```

Actual dimensions configurable.

Image engine:

```text
Imagick → preferred jika tersedia
GD      → fallback
```

WebP dibuat jika PHP image library mendukung.

Frontend wajib menggunakan:

- responsive `srcset`;
- width/height attributes;
- lazy loading;
- optimized image variant.

---

# 14. Comment System

Comment feature:

- comments per post;
- nested reply dengan depth terbatas;
- pending;
- approved;
- spam;
- trash;
- comment close/open per content.

Moderasi dapat dilakukan oleh user dengan capability yang sesuai.

Comment count dapat disimpan sebagai precomputed counter pada content untuk menghindari `COUNT(*)` berulang.

---

# 15. Advertising Management

Advertising merupakan module resmi.

Placement default:

```text
Header
Sidebar
Before Article
After Paragraph N
Middle Article
After Article
Sticky
Footer
```

Targeting:

- site;
- content type;
- category;
- placement;
- desktop/mobile;
- active date range.

Block editor menyediakan:

```text
ad_slot
```

Contoh:

```json
{
  "type": "ad_slot",
  "placement": "article_middle"
}
```

Script Google AdSense / Google Ad Manager dapat digunakan tanpa menyimpan business logic ke theme.

---

# 16. Analytics Integration

Tidak membuat analytics engine sendiri.

CMS menyediakan integration setting untuk:

```text
Google Analytics
Matomo
```

Theme dapat memanggil:

```php
analytics_head()
analytics_body()
```

atau equivalent hook.

---

# 17. Search

Tidak menggunakan Elasticsearch pada MVP.

Search menggunakan MySQL/MariaDB.

Prioritas:

```text
Title
Excerpt
Content plain text
Category
Tag
Author
```

Jika database mendukung FULLTEXT, gunakan FULLTEXT index.

Fallback harus tetap tersedia apabila konfigurasi shared hosting membatasi fitur database tertentu.

Search result wajib paginated.

Tidak diperbolehkan:

```sql
LIKE '%keyword%'
```

terhadap banyak kolom besar pada setiap request jika alternatif indexed search tersedia.

---

# 18. Plugin Architecture

CMS memiliki plugin system sendiri.

Tidak kompatibel dengan WordPress plugin.

Konsep utama:

```text
Actions
Filters
Events
Services
Routes
Permissions
Blocks
Content Types
Taxonomies
CLI/Migration hooks bila tersedia
```

Contoh:

```php
add_action('content.published', $callback);

add_filter('content.rendered', $callback);

register_content_type('video', [...]);

register_block('poll', [...]);
```

Plugin harus mempunyai manifest.

Contoh:

```json
{
  "name": "related-posts",
  "version": "1.0.0",
  "requires_core": ">=1.0",
  "bootstrap": "Plugin.php"
}
```

Plugin dapat diaktifkan atau dinonaktifkan per site apabila kompatibel dengan multisite.

---

# 19. Plugin Safety Rules

Plugin tidak boleh mengubah core file.

Plugin migration harus terisolasi.

Plugin mempunyai namespace sendiri.

Core menyediakan lifecycle:

```text
install
activate
deactivate
uninstall
```

Automatic marketplace/update tidak termasuk MVP.

Deployment dilakukan manual melalui FTP/File Manager.

---

# 20. Theme System

Theme terpisah dari core.

Template resolution dapat mengikuti pola:

```text
home
single
single-{content-type}
page
archive
category
tag
author
search
404
```

Theme mempunyai manifest.

Contoh:

```json
{
  "name": "portal-default",
  "version": "1.0.0",
  "supports": [
    "menus",
    "widgets",
    "featured-images"
  ]
}
```

Theme tidak boleh langsung melakukan SQL query.

Theme hanya menggunakan CMS query/service API.

---

# 21. Frontend Architecture

Default production architecture:

```text
Browser
   ↓
Apache
   ↓
Public Front Controller
   ↓
Full Page Cache
   │
   ├─ HIT → HTML
   │
   └─ MISS
       ↓
     Router
       ↓
     Content Service
       ↓
     Theme Renderer
       ↓
     Cache HTML
       ↓
     Response
```

Frontend publik dan backend admin terpisah secara logical/application layer, walaupun dapat ditempatkan dalam hosting yang sama.

---

# 22. Admin Frontend

Admin dapat menggunakan:

```text
Vanilla JS
atau
Vue/React compiled SPA
```

Jika Vue/React digunakan:

Node.js hanya diperlukan saat development/build.

Production:

```text
HTML
JS bundle
CSS bundle
PHP API
```

Tidak membutuhkan Node.js runtime.

---

# 23. REST API

Base path:

```text
/api/v1/
```

Public API bersifat read-only.

Contoh:

```text
GET /api/v1/posts
GET /api/v1/posts/{slug}
GET /api/v1/categories
GET /api/v1/tags
GET /api/v1/authors/{id}
```

Write operations:

```text
POST
PUT
PATCH
DELETE
```

wajib authenticated dan melewati capability check.

REST controller tidak boleh berisi business logic utama.

Flow:

```text
API Controller
      ↓
Application Service
      ↓
Repository
```

Dengan demikian admin, frontend submission, plugin, dan API memakai logic yang sama.

---

# 24. Database Architecture

Database harus menghindari pola generic EAV sebagai penyimpanan utama.

Domain utama:

```text
sites
users
contents
content_revisions
content_seo
content_terms
terms
taxonomies
media
comments
redirects
notifications
workflow_logs
settings
```

Core foreign key/index dirancang berdasarkan query paling umum.

Seluruh tabel site-specific wajib mempunyai:

```text
site_id
```

pada index yang relevan.

---

# 25. Query Budget

Target public cached page:

```text
0 database queries
```

Target uncached single article:

```text
≤ 5 logical database queries
```

Target archive/category page:

```text
≤ 5 logical database queries
```

Target admin tidak seketat frontend tetapi harus mencegah N+1 queries.

Repository harus mendukung eager/batched fetching.

---

# 26. Caching Architecture

Caching merupakan fitur inti.

Layer:

```text
L1 — Request Memory Cache
L2 — File Object/Data Cache
L3 — Full Page HTML Cache
L4 — Browser/HTTP Cache
```

Tidak membutuhkan Redis.

Contoh:

```text
/storage/cache/
    pages/
    objects/
    queries/
    sitemap/
```

---

# 27. Full Page Cache

Public anonymous pages dapat disimpan sebagai HTML.

Contoh cache key:

```text
site:{site_id}:page:{normalized_path}
```

Pada filesystem dapat di-hash:

```text
/storage/cache/pages/{site_id}/{hash}.html
```

Cache file mempunyai metadata:

```text
created_at
content_type
dependencies
```

Authenticated admin/editor tidak menggunakan public full-page cache.

---

# 28. Cache Invalidation

Tidak menggunakan TTL panjang sebagai mekanisme utama consistency.

Menggunakan event-based invalidation.

Contoh:

```text
content.updated
      ↓
CacheInvalidationService
      ↓
invalidate:
- single article
- homepage
- related category
- related tag
- author archive
- sitemap
- relevant API cache
```

Ketika artikel dipublish/update:

1. database transaction selesai;
2. event diterbitkan;
3. cache terkait dihapus;
4. halaman artikel diregenerasi;
5. response publish sukses diberikan.

Dengan demikian perubahan artikel langsung terlihat.

---

# 29. Cache Dependency Map

Sistem perlu mengetahui dependency.

Contoh:

```text
Post #42
 ├─ /news/article-slug
 ├─ /
 ├─ /category/technology
 ├─ /tag/ai
 └─ /author/john
```

Tidak boleh melakukan:

```text
delete seluruh cache website
```

untuk setiap edit artikel kecuali fallback darurat.

---

# 30. Multisite

Satu codebase dapat menjalankan banyak portal.

Site resolution:

```text
Request Host
    ↓
Domain Resolver
    ↓
site_id
```

Mendukung:

```text
news.example.com
portal.example.com
domainlain.com
```

Domain mapping disimpan pada database dengan cache file untuk lookup cepat.

---

# 31. Multisite Isolation

Setiap site memiliki:

- user sendiri;
- content sendiri;
- theme sendiri;
- plugin configuration sendiri;
- SEO setting sendiri;
- media sendiri;
- ads sendiri;
- settings sendiri.

User tidak dibagikan antar-site.

Role user pada Site A tidak memberikan akses ke Site B.

Semua repository wajib menerapkan site scope.

Tidak boleh bergantung pada developer mengingat menambahkan:

```sql
WHERE site_id = ?
```

secara manual.

Site context harus diaplikasikan pada repository/data layer.

---

# 32. Notification System

Notification bersifat optional.

Channel:

```text
Internal
Email
```

Event:

```text
content_submitted
revision_requested
content_approved
content_scheduled
content_published
```

Setiap event/channel dapat:

```text
ON
OFF
```

per site.

Email delivery dilakukan synchronously atau dengan cron-compatible outbox ringan.

Tidak membutuhkan queue daemon.

---

# 33. Scheduling

Scheduled post menggunakan cron jika tersedia.

Contoh:

```text
*/5 * * * * php cron.php
```

Jika shared hosting mempunyai cron terbatas, sistem menyediakan lazy fallback:

request website dapat memicu lightweight scheduled-task check dengan locking.

---

# 34. WordPress Migration

CMS menyediakan migration/import tool.

Data minimum:

```text
Posts
Pages
Categories
Tags
Users/Authors
Featured Images
Media Library
Comments
Slugs
Permalinks
Publish dates
Custom fields
Yoast SEO metadata
```

Migrator wajib mempertahankan URL lama jika memungkinkan.

Jika URL berubah, redirect 301 otomatis dapat dibuat.

---

# 35. Yoast Migration

Importer memetakan Yoast metadata ke SEO core.

Contoh:

```text
_wpseo_title
→ meta_title

_wpseo_metadesc
→ meta_description

_wpseo_canonical
→ canonical_url
```

Field yang tidak dikenali disimpan ke migration log untuk review, bukan dibuang diam-diam.

---

# 36. Performance Requirements

Target awal pada shared hosting standar:

```text
Cached page:
TTFB target < 200 ms jika hosting memungkinkan.

Database query cached page:
0

Memory PHP typical public request:
< 32 MB target

Admin request:
< 128 MB target
```

Angka riil akan dipengaruhi shared hosting provider, sehingga target utama adalah relative application overhead.

---

# 37. Security Requirements

Minimal:

- prepared statements;
- output escaping;
- CSRF protection;
- secure sessions;
- password hashing;
- upload MIME validation;
- upload extension whitelist;
- permission check pada setiap mutation;
- directory execution protection pada uploads;
- rate limiting login;
- secure reset token;
- SameSite cookies;
- HTTP-only auth cookies.

Plugin tidak boleh mendapatkan privileged API tanpa capability yang sesuai.

---

# 38. Logging

Logging file-based:

```text
/storage/logs/
```

Level minimum:

```text
ERROR
WARNING
INFO
```

Log rotation sederhana diperlukan agar shared hosting disk tidak penuh.

Debug logging default OFF pada production.

---

# 39. Error Handling

Visitor tidak boleh melihat:

```text
SQL error
stack trace
filesystem path
secret/config
```

Production error page menggunakan generic error response.

Detail error masuk log.

---

# 40. Configuration

Environment-specific configuration:

```text
/config/
.env atau equivalent
```

Secret tidak boleh berada pada repository publik.

Site setting tersimpan terpisah dari environment configuration.

---

# 41. Directory Structure

Contoh target:

```text
/app
  /Core
  /Http
  /Domain
  /Services
  /Repositories
  /Events
  /Cache
  /Security

/admin

/api

/plugins

/themes

/public
  index.php
  /assets
  /uploads

/storage
  /cache
  /logs
  /sessions

/config

/database
  /migrations

/bin
```

Jika document root dapat dikonfigurasi:

```text
/public
```

harus menjadi satu-satunya web-accessible application root.

---

# 42. Architecture Rule

Dependency direction:

```text
Controller
    ↓
Service
    ↓
Repository
    ↓
Database
```

Dilarang:

```text
Theme → raw SQL

Plugin → modify core source

Controller → direct SQL

Block → raw DB query
```

---

# 43. Event Bus

Core menyediakan synchronous lightweight event bus.

Contoh event:

```text
content.created
content.updated
content.submitted
content.approved
content.published
content.deleted

comment.created
comment.approved

media.created

site.updated

theme.changed
plugin.activated
```

Cache, notification, SEO sitemap, dan plugin dapat subscribe ke event.

Dengan demikian module tidak perlu saling meng-import secara langsung.

---

# 44. Agent-Oriented Development Model

Karena sistem akan dibangun oleh beberapa agent, core dibagi berdasarkan bounded context.

Setiap agent wajib hanya mengubah module miliknya kecuali kontrak bersama mengharuskan perubahan.

### Agent 1 — Core Platform

Ownership:

```text
Bootstrap
DI/service container ringan
Router
Middleware
Config
Events
Error handling
Filesystem
Database abstraction
Migration framework
```

Deliverable utama adalah foundation yang digunakan agent lain.

---

### Agent 2 — Identity & RBAC

Ownership:

```text
Users
Authentication
Sessions
Password reset
Roles
Capabilities
Permission middleware
```

API kontrak:

```text
AuthService
UserRepository
PermissionService
```

---

### Agent 3 — Content Engine

Ownership:

```text
Posts
Pages
Custom Post Types
Taxonomies
Slug
Content queries
Revision
Autosave
```

API utama:

```text
ContentService
ContentRepository
TaxonomyService
RevisionService
```

---

### Agent 4 — Editorial Workflow

Ownership:

```text
Draft
Submission
Review
Revision request
Approval
Scheduling
Publishing
Workflow audit
```

Menggunakan ContentService tetapi tidak melakukan SQL langsung ke content tables.

---

### Agent 5 — Cache & Performance

Ownership:

```text
File cache
Object cache
Full page cache
Cache keys
Invalidation
Dependency map
HTTP cache headers
```

Subscribe ke:

```text
content.*
taxonomy.*
theme.*
site.*
```

Agent ini tidak menentukan business rules content.

---

### Agent 6 — SEO

Ownership:

```text
Metadata
Canonical
OpenGraph
Schema
Sitemap
Breadcrumb
Redirects
SEO analysis
```

API:

```text
SeoService
SitemapService
RedirectService
```

---

### Agent 7 — Media

Ownership:

```text
Upload
Validation
Resize
Compression
WebP
EXIF stripping
Media library
Responsive image helpers
```

---

### Agent 8 — Block Editor

Ownership:

```text
Block schema
Block registry
JSON validation
Renderer
Built-in blocks
Admin editor interface
```

Plugin API:

```text
register_block()
```

---

### Agent 9 — Theme Engine

Ownership:

```text
Theme registry
Template resolver
Template rendering
Menus
Theme config
Frontend integration helpers
```

Tidak boleh mengakses database secara langsung.

---

### Agent 10 — Plugin Platform

Ownership:

```text
Plugin discovery
Manifest
Lifecycle
Hooks
Actions
Filters
Plugin migrations
Plugin permissions
```

---

### Agent 11 — REST API

Ownership:

```text
/api/v1
serialization
pagination
authentication adapter
public read endpoints
authenticated write endpoints
```

REST API menggunakan service layer milik domain lain.

---

### Agent 12 — Multisite

Ownership:

```text
Site registry
Domain mapping
Site context
Site isolation
Per-site settings
Plugin/theme assignment
```

Agent ini menyediakan:

```text
SiteContext
SiteResolver
```

yang wajib digunakan semua repository.

---

### Agent 13 — Comments & Frontend Submission

Ownership:

```text
Comments
Comment moderation
Frontend author dashboard
Article submission UX
Revision responses
```

Tidak membuat content engine sendiri.

---

### Agent 14 — Ads & Integrations

Ownership:

```text
Ad placements
Ad rules
Ad block
Google Analytics
Matomo
```

---

### Agent 15 — WordPress Migration

Ownership:

```text
WordPress reader
Post import
Term import
User import
Media migration
Comment migration
Yoast migration
URL preservation
Redirect generation
Migration report
```

Tidak mengubah schema agar menyerupai WordPress.

Importer harus menggunakan CMS service layer.

---

# 45. Shared Contracts

Sebelum agent bekerja paralel, kontrak berikut harus dibekukan:

```text
Content entity
User entity
SiteContext
Event names
Repository interfaces
Plugin manifest
Theme manifest
Block schema
REST response format
Cache interface
```

Contract definitions ditempatkan pada core package dan memiliki versioning.

---

# 46. Standard Service Contracts

Contoh:

```php
interface CacheInterface
{
    public function get(string $key): mixed;
    public function set(
        string $key,
        mixed $value,
        int $ttl = 0
    ): void;
    public function delete(string $key): void;
}
```

Content:

```php
interface ContentRepositoryInterface
{
    public function find(int $id): ?Content;
    public function findBySlug(string $slug): ?Content;
    public function save(Content $content): void;
}
```

Agent lain bergantung pada interface, bukan concrete implementation.

---

# 47. Event Contract Example

Publish:

```text
ContentService.publish()
        ↓
Database transaction
        ↓
ContentPublished event
        ↓
 ┌──────────────┬──────────────┬──────────────┐
 ↓              ↓              ↓
Cache         Sitemap       Notification
Agent         Agent          Agent
```

Ini menjadi mekanisme koordinasi antar-agent.

---

# 48. API Response Standard

Success:

```json
{
  "data": {},
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
    "total": 182
  }
}
```

Error:

```json
{
  "error": {
    "code": "CONTENT_NOT_FOUND",
    "message": "Content not found."
  }
}
```

---

# 49. Module Testing Requirement

Setiap agent wajib menghasilkan:

```text
Unit tests
Integration tests
Migration tests jika relevan
Contract tests
Basic security tests
```

Agent tidak dianggap selesai hanya karena UI bekerja.

---

# 50. Performance Test Scenarios

Minimum benchmark:

### Scenario A
1 artikel cached dibuka 100 kali.

Expectation:

```text
0 DB query/request
```

setelah cache dibuat.

### Scenario B
Artikel di-update.

Expectation:

```text
DB updated
cache invalidated
new version immediately visible
```

### Scenario C
Homepage cached.

Artikel baru publish.

Expectation:

```text
homepage cache refreshed/invalidated
single cache generated
category cache refreshed/invalidated
sitemap refreshed
```

---

# 51. Database Regression Test

Setiap public endpoint mempunyai query budget.

CI/test environment harus bisa mencatat query count.

Perubahan yang menaikkan query secara drastis dianggap regression.

---

# 52. MVP Included

MVP mencakup seluruh foundation berikut:

```text
Lightweight PHP core
Authentication
RBAC
Posts
Pages
Custom Post Type
Taxonomy
Block editor
Media optimization
Editorial workflow
Frontend submission
Comments
SEO
Sitemap
Redirects
Theme system
Plugin system
REST API
Full page cache
File/object cache
Multisite
Custom domain
Ads
Analytics integrations
Search
Revision max 3
Notification toggle
WordPress migration
Yoast migration
```

---

# 53. Explicitly Out of Scope

Versi awal tidak membutuhkan:

```text
WordPress plugin compatibility
Full Elementor clone
Multilingual
Built-in analytics
Built-in backup
2FA
Automatic plugin marketplace
Automatic CMS updater
Redis
Memcached
Elasticsearch
Node.js production runtime
Real-time websocket system
```

---

# 54. Suggested Implementation Sequence

Walaupun agent bekerja paralel, dependency order logis adalah:

```text
CORE CONTRACTS
      ↓
Core + Multisite + Auth
      ↓
Content Engine
      ↓
┌────────────┬─────────────┬─────────────┐
SEO        Workflow       Media        Cache
│             │             │             │
└─────────────┴──────┬──────┴─────────────┘
                     ↓
             Theme + Block Engine
                     ↓
          API + Frontend Submission
                     ↓
       Plugins + Ads + Comments
                     ↓
             WP Migration
                     ↓
          Hardening & Benchmark
```

Beberapa module dapat dikerjakan paralel setelah shared contract stabil.

---

# 55. Definition of Done

CMS dianggap mencapai production-ready v1 apabila:

1. WordPress sample site dapat dimigrasikan tanpa kehilangan URL penting.
2. Artikel dapat dibuat, direview, direvisi, dijadwalkan, dan dipublish.
3. Visitor anonymous menerima full-page cached HTML.
4. Cached article membutuhkan 0 query database.
5. Perubahan artikel langsung terlihat setelah update.
6. SEO metadata dan sitemap valid.
7. Image upload menghasilkan optimized variants.
8. Contributor dapat submit dari frontend.
9. Multisite bekerja pada subdomain dan custom domain.
10. User satu site tidak dapat mengakses site lain.
11. Plugin dapat mendaftarkan hook, content type, taxonomy, dan block tanpa mengubah core.
12. Theme dapat diganti tanpa memodifikasi content.
13. Public REST API read-only.
14. Mutation API memerlukan auth + capability.
15. Revision tidak melebihi 3 snapshot.
16. Tidak ada runtime dependency pada Node.js, Redis, Elasticsearch, atau queue daemon.
17. Sistem dapat di-deploy menggunakan FTP/File Manager pada shared hosting standar.

---

# 56. Primary Success Metric

Metric teknis utama bukan jumlah fitur, tetapi:

**berapa sedikit pekerjaan PHP dan database yang diperlukan untuk melayani satu visitor.**

Target ideal:

```text
Published Content
      ↓
Compiled HTML
      ↓
Full Page Cache
      ↓
Visitor
```

bukan:

```text
Visitor
   ↓
Bootstrap CMS besar
   ↓
Load puluhan plugin
   ↓
Query content
   ↓
Query metadata
   ↓
Query author
   ↓
Query taxonomy
   ↓
Query options
   ↓
Render
```

Ini merupakan prinsip arsitektur utama yang membedakan CMS ini dari WordPress.

---

# 57. Product Direction

Produk bukan ditujukan sebagai “WordPress clone”.

Produk adalah:

**lightweight publishing platform dengan filosofi WordPress pada extensibility, tetapi menggunakan arsitektur modern yang lebih deterministic, cache-first, database-efficient, dan cocok untuk shared hosting.**

Core harus kecil.

Feature besar harus dapat menjadi module.

Frontend tidak boleh bergantung langsung pada database.

Business logic harus reusable.

Cache harus dianggap sebagai bagian arsitektur, bukan plugin tambahan.

Dan setiap domain harus cukup terisolasi sehingga satu autonomous coding agent dapat mengerjakannya tanpa perlu memahami seluruh codebase.