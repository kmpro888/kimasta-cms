# Database Schema & ERD Specification

**Project:** Lightweight Portal CMS  
**Version:** 1.0  
**Database:** MySQL / MariaDB  
**Target:** Shared Hosting  
**Architecture:** Multisite Modular Monolith  
**Primary Goal:** Database-efficient, cache-first publishing platform

---

# 1. Database Design Principles

Database harus mengikuti prinsip berikut:

1. Semua data portal bersifat `site-scoped`.
2. Hindari model EAV seperti `wp_postmeta` untuk data inti.
3. Kolom yang sering digunakan harus menjadi first-class column.
4. Gunakan index berdasarkan pola query nyata.
5. Hindari N+1 query.
6. Hindari `SELECT *` pada high-traffic query.
7. Generic metadata hanya boleh digunakan untuk extension/plugin.
8. Cache bukan pengganti index database.
9. Semua foreign key ownership harus jelas.
10. Multisite isolation wajib diterapkan di repository layer.

---

# 2. Database Engine

Recommended:

```text
MySQL 8.x
```

atau:

```text
MariaDB 10.x+
```

Storage engine:

```text
InnoDB
```

Character set:

```text
utf8mb4
```

Collation recommended:

```text
utf8mb4_unicode_ci
```

atau collation kompatibel yang tersedia pada hosting.

---

# 3. ID Strategy

Default primary key:

```sql
BIGINT UNSIGNED AUTO_INCREMENT
```

Contoh:

```sql
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
```

Tidak menggunakan UUID untuk internal database MVP.

---

# 4. Timestamp Standard

Semua timestamp disimpan dalam UTC.

Standard:

```sql
created_at DATETIME NOT NULL
updated_at DATETIME NOT NULL
```

Optional:

```sql
deleted_at DATETIME NULL
```

jika benar-benar membutuhkan soft delete.

---

# 5. Core ERD Overview

```text
                         ┌──────────────┐
                         │    sites     │
                         └──────┬───────┘
                                │
          ┌─────────────────────┼─────────────────────┐
          │                     │                     │
          ▼                     ▼                     ▼
     site_domains            users                contents
          │                     │                     │
          │                     │          ┌──────────┼───────────┐
          │                     │          │          │           │
          │                     ▼          ▼          ▼           ▼
          │                user_roles  revisions   seo       comments
          │
          │
          ├─────────────────────────────┐
          │                             │
          ▼                             ▼
     site_settings                  site_plugins


contents
   │
   ├─────────────── media
   │
   ├─────────────── content_terms ───── terms ───── taxonomies
   │
   ├─────────────── workflow_logs
   │
   └─────────────── comments


sites
   │
   ├── ads
   ├── redirects
   ├── notifications
   ├── themes
   └── plugins
```

---

# 6. Table Groups

Database dibagi secara konseptual menjadi:

```text
Platform
Identity
Content
Taxonomy
SEO
Media
Editorial
Comments
Plugin
Theme
Advertising
Notification
Migration
System
```

---

# 7. `sites`

Menyimpan tenant/site.

```sql
CREATE TABLE sites (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(150) NOT NULL,

    status VARCHAR(20) NOT NULL DEFAULT 'active',

    primary_domain VARCHAR(255) NOT NULL,

    timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Jakarta',

    active_theme VARCHAR(120) NULL,

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    UNIQUE KEY uq_sites_primary_domain (primary_domain),
    KEY idx_sites_status (status)
);
```

Allowed status:

```text
active
inactive
suspended
```

---

# 8. `site_domains`

Satu site dapat mempunyai beberapa domain.

```sql
CREATE TABLE site_domains (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    domain VARCHAR(255) NOT NULL,

    is_primary TINYINT(1) NOT NULL DEFAULT 0,

    created_at DATETIME NOT NULL,

    UNIQUE KEY uq_site_domains_domain (domain),
    KEY idx_site_domains_site (site_id),

    CONSTRAINT fk_site_domains_site
        FOREIGN KEY (site_id)
        REFERENCES sites(id)
        ON DELETE CASCADE
);
```

Contoh:

```text
site_id = 1

portalutama.com
news.portalutama.com
domainkedua.com
```

---

# 9. Domain Resolution Optimization

Karena query domain terjadi hampir setiap request, database bukan satu-satunya lookup mechanism.

System menghasilkan:

```text
/storage/cache/system/domain-map.php
```

Contoh:

```php
return [
    'portalutama.com' => 1,
    'news.portalutama.com' => 1,
    'domainkedua.com' => 1,
];
```

Database hanya fallback.

---

# 10. `site_settings`

```sql
CREATE TABLE site_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    namespace VARCHAR(80) NOT NULL,

    setting_key VARCHAR(120) NOT NULL,

    setting_value LONGTEXT NULL,

    autoload TINYINT(1) NOT NULL DEFAULT 0,

    updated_at DATETIME NOT NULL,

    UNIQUE KEY uq_site_setting (
        site_id,
        namespace,
        setting_key
    ),

    KEY idx_site_settings_autoload (
        site_id,
        autoload
    )
);
```

Contoh:

```text
seo.default_title
seo.organization_name

notifications.content_published.email

analytics.ga_id

permalink.post_structure
```

Autoload settings dikompilasi/cache agar tidak query berulang.

---

# 11. `users`

User tidak dibagi antar-site.

```sql
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    username VARCHAR(80) NOT NULL,

    email VARCHAR(190) NOT NULL,

    password_hash VARCHAR(255) NOT NULL,

    display_name VARCHAR(150) NOT NULL,

    status VARCHAR(20) NOT NULL DEFAULT 'active',

    last_login_at DATETIME NULL,

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    UNIQUE KEY uq_users_username (
        site_id,
        username
    ),

    UNIQUE KEY uq_users_email (
        site_id,
        email
    ),

    KEY idx_users_site_status (
        site_id,
        status
    )
);
```

User email yang sama boleh ada di site berbeda.

---

# 12. `roles`

Role dapat berupa system default atau custom.

```sql
CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(80) NOT NULL,

    slug VARCHAR(80) NOT NULL,

    is_system TINYINT(1) NOT NULL DEFAULT 0,

    created_at DATETIME NOT NULL,

    UNIQUE KEY uq_roles_slug (
        site_id,
        slug
    )
);
```

Default:

```text
administrator
editor
reviewer
author
contributor
subscriber
```

---

# 13. `capabilities`

Capability registry global.

```sql
CREATE TABLE capabilities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    capability_key VARCHAR(120) NOT NULL,

    description VARCHAR(255) NULL,

    UNIQUE KEY uq_capability_key (
        capability_key
    )
);
```

Examples:

```text
content.create
content.edit_own
content.edit_all
content.submit
content.review
content.approve
content.publish
media.upload
comment.moderate
user.manage
seo.manage
plugin.manage
site.manage
```

---

# 14. `role_capabilities`

```sql
CREATE TABLE role_capabilities (
    role_id BIGINT UNSIGNED NOT NULL,

    capability_id BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (
        role_id,
        capability_id
    )
);
```

---

# 15. `user_roles`

MVP tetap mengizinkan satu user memiliki lebih dari satu role dalam site yang sama.

```sql
CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,

    role_id BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (
        user_id,
        role_id
    )
);
```

---

# 16. `password_resets`

```sql
CREATE TABLE password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    user_id BIGINT UNSIGNED NOT NULL,

    token_hash VARCHAR(255) NOT NULL,

    expires_at DATETIME NOT NULL,

    used_at DATETIME NULL,

    created_at DATETIME NOT NULL,

    KEY idx_password_reset_user (
        site_id,
        user_id
    ),

    KEY idx_password_reset_expiry (
        expires_at
    )
);
```

Token plaintext tidak disimpan.

---

# 17. `contents`

Ini tabel paling penting.

```sql
CREATE TABLE contents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    type VARCHAR(80) NOT NULL,

    author_id BIGINT UNSIGNED NOT NULL,

    title VARCHAR(255) NOT NULL,

    slug VARCHAR(220) NOT NULL,

    excerpt TEXT NULL,

    content_json LONGTEXT NULL,

    content_html LONGTEXT NULL,

    content_plain LONGTEXT NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'draft',

    featured_media_id BIGINT UNSIGNED NULL,

    comment_status VARCHAR(20) NOT NULL DEFAULT 'open',

    published_at DATETIME NULL,

    scheduled_at DATETIME NULL,

    created_at DATETIME NOT NULL,

    updated_at DATETIME NOT NULL,

    UNIQUE KEY uq_contents_slug (
        site_id,
        type,
        slug
    ),

    KEY idx_contents_archive (
        site_id,
        type,
        status,
        published_at
    ),

    KEY idx_contents_author (
        site_id,
        author_id,
        status
    ),

    KEY idx_contents_schedule (
        site_id,
        status,
        scheduled_at
    )
);
```

---

# 18. Why `content_html` Exists

Content source:

```text
content_json
```

merupakan canonical block representation.

Pada save:

```text
content_json
     ↓
Block Compiler
     ↓
content_html
```

Frontend tidak perlu compile block setiap page view.

---

# 19. Why `content_plain` Exists

Digunakan untuk:

```text
search
excerpt generation
migration
content analysis
SEO analysis
```

Sehingga pencarian tidak perlu membersihkan HTML setiap query.

---

# 20. Content Status

Canonical:

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

Database tidak memakai ENUM agar plugin/workflow migration lebih fleksibel.

Validation dilakukan application layer.

---

# 21. Content Query — Homepage

Typical:

```sql
SELECT
    id,
    type,
    author_id,
    title,
    slug,
    excerpt,
    featured_media_id,
    published_at
FROM contents
WHERE site_id = ?
AND type = 'post'
AND status = 'published'
AND published_at <= UTC_TIMESTAMP()
ORDER BY published_at DESC
LIMIT 20;
```

Index:

```text
(site_id, type, status, published_at)
```

---

# 22. `content_revisions`

```sql
CREATE TABLE content_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    content_id BIGINT UNSIGNED NOT NULL,

    created_by BIGINT UNSIGNED NOT NULL,

    title VARCHAR(255) NOT NULL,

    excerpt TEXT NULL,

    content_json LONGTEXT NULL,

    content_html LONGTEXT NULL,

    revision_number INT UNSIGNED NOT NULL,

    created_at DATETIME NOT NULL,

    KEY idx_revision_content (
        site_id,
        content_id,
        created_at
    )
);
```

Maximum:

```text
3 revisions/content
```

Revision ke-4 menyebabkan revision tertua dihapus.

---

# 23. Autosave

Autosave tidak masuk `content_revisions`.

Gunakan tabel terpisah.

```sql
CREATE TABLE content_autosaves (
    content_id BIGINT UNSIGNED NOT NULL,

    user_id BIGINT UNSIGNED NOT NULL,

    site_id BIGINT UNSIGNED NOT NULL,

    content_json LONGTEXT NULL,

    updated_at DATETIME NOT NULL,

    PRIMARY KEY (
        content_id,
        user_id
    )
);
```

Satu autosave aktif per user/content.

---

# 24. `taxonomies`

```sql
CREATE TABLE taxonomies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(80) NOT NULL,

    content_type VARCHAR(80) NULL,

    hierarchical TINYINT(1) NOT NULL DEFAULT 0,

    created_at DATETIME NOT NULL,

    UNIQUE KEY uq_taxonomy_name (
        site_id,
        name,
        content_type
    )
);
```

Default:

```text
category
tag
```

---

# 25. `terms`

```sql
CREATE TABLE terms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    taxonomy_id BIGINT UNSIGNED NOT NULL,

    parent_id BIGINT UNSIGNED NULL,

    name VARCHAR(150) NOT NULL,

    slug VARCHAR(180) NOT NULL,

    description TEXT NULL,

    content_count INT UNSIGNED NOT NULL DEFAULT 0,

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    UNIQUE KEY uq_term_slug (
        site_id,
        taxonomy_id,
        slug
    ),

    KEY idx_terms_taxonomy (
        site_id,
        taxonomy_id
    ),

    KEY idx_terms_parent (
        parent_id
    )
);
```

`content_count` merupakan precomputed counter.

Tidak menjalankan `COUNT(*)` setiap archive page.

---

# 26. `content_terms`

```sql
CREATE TABLE content_terms (
    content_id BIGINT UNSIGNED NOT NULL,

    term_id BIGINT UNSIGNED NOT NULL,

    site_id BIGINT UNSIGNED NOT NULL,

    sort_order INT NOT NULL DEFAULT 0,

    PRIMARY KEY (
        content_id,
        term_id
    ),

    KEY idx_content_terms_term (
        site_id,
        term_id,
        content_id
    )
);
```

`site_id` sengaja tetap disimpan untuk mempercepat tenant-scoped lookup.

---

# 27. `content_seo`

```sql
CREATE TABLE content_seo (
    content_id BIGINT UNSIGNED PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    meta_title VARCHAR(255) NULL,

    meta_description VARCHAR(320) NULL,

    canonical_url VARCHAR(500) NULL,

    robots_index TINYINT(1) NOT NULL DEFAULT 1,

    robots_follow TINYINT(1) NOT NULL DEFAULT 1,

    og_title VARCHAR(255) NULL,

    og_description VARCHAR(320) NULL,

    og_image_id BIGINT UNSIGNED NULL,

    schema_type VARCHAR(80) NULL,

    updated_at DATETIME NOT NULL,

    KEY idx_content_seo_site (
        site_id
    )
);
```

Tidak memakai:

```text
meta_key
meta_value
```

untuk SEO core.

---

# 28. Optional Generic Metadata

Plugin terkadang membutuhkan arbitrary metadata.

Untuk kebutuhan tersebut boleh dibuat:

```sql
CREATE TABLE entity_meta (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    entity_type VARCHAR(80) NOT NULL,

    entity_id BIGINT UNSIGNED NOT NULL,

    meta_key VARCHAR(120) NOT NULL,

    meta_value LONGTEXT NULL,

    KEY idx_entity_meta_lookup (
        site_id,
        entity_type,
        entity_id,
        meta_key
    )
);
```

Namun:

**core feature MUST NOT bergantung pada tabel ini jika field layak menjadi first-class column/table.**

---

# 29. `media`

```sql
CREATE TABLE media (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    uploaded_by BIGINT UNSIGNED NOT NULL,

    original_name VARCHAR(255) NULL,

    path VARCHAR(500) NOT NULL,

    mime_type VARCHAR(100) NOT NULL,

    extension VARCHAR(20) NOT NULL,

    width INT UNSIGNED NULL,

    height INT UNSIGNED NULL,

    filesize BIGINT UNSIGNED NOT NULL,

    alt_text VARCHAR(255) NULL,

    caption TEXT NULL,

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    KEY idx_media_site_date (
        site_id,
        created_at
    ),

    KEY idx_media_user (
        site_id,
        uploaded_by
    )
);
```

Store relative path, bukan URL absolut.

---

# 30. `media_variants`

```sql
CREATE TABLE media_variants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    media_id BIGINT UNSIGNED NOT NULL,

    site_id BIGINT UNSIGNED NOT NULL,

    variant VARCHAR(50) NOT NULL,

    format VARCHAR(20) NOT NULL,

    path VARCHAR(500) NOT NULL,

    width INT UNSIGNED NULL,

    height INT UNSIGNED NULL,

    filesize BIGINT UNSIGNED NULL,

    UNIQUE KEY uq_media_variant (
        media_id,
        variant,
        format
    ),

    KEY idx_media_variant_site (
        site_id,
        media_id
    )
);
```

Examples:

```text
thumbnail/jpeg
thumbnail/webp
medium/jpeg
medium/webp
large/jpeg
large/webp
```

---

# 31. Media Filesystem

Recommended:

```text
/uploads/{site_id}/{year}/{month}/
```

Contoh:

```text
/uploads/2/2026/10/image.webp
```

Mencegah konflik filename antar-site.

---

# 32. `workflow_logs`

Audit editorial.

```sql
CREATE TABLE workflow_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    content_id BIGINT UNSIGNED NOT NULL,

    user_id BIGINT UNSIGNED NOT NULL,

    from_status VARCHAR(30) NULL,

    to_status VARCHAR(30) NOT NULL,

    note TEXT NULL,

    created_at DATETIME NOT NULL,

    KEY idx_workflow_content (
        site_id,
        content_id,
        created_at
    ),

    KEY idx_workflow_user (
        site_id,
        user_id,
        created_at
    )
);
```

---

# 33. `comments`

```sql
CREATE TABLE comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    content_id BIGINT UNSIGNED NOT NULL,

    parent_id BIGINT UNSIGNED NULL,

    user_id BIGINT UNSIGNED NULL,

    author_name VARCHAR(150) NULL,

    author_email VARCHAR(190) NULL,

    body TEXT NOT NULL,

    status VARCHAR(20) NOT NULL DEFAULT 'pending',

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    KEY idx_comments_content (
        site_id,
        content_id,
        status,
        created_at
    ),

    KEY idx_comments_parent (
        parent_id
    )
);
```

Status:

```text
pending
approved
spam
trash
```

---

# 34. Comment Count Optimization

Tambahkan ke contents:

```sql
comment_count INT UNSIGNED NOT NULL DEFAULT 0
```

atau gunakan table counter terpisah.

Recommended untuk MVP:

tambahkan `comment_count` ke `contents`.

Update hanya saat comment approved/unapproved.

---

# 35. `redirects`

```sql
CREATE TABLE redirects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    source_path VARCHAR(500) NOT NULL,

    target_url VARCHAR(1000) NOT NULL,

    status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,

    hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    UNIQUE KEY uq_redirect_source (
        site_id,
        source_path
    )
);
```

Lookup harus exact/indexed.

---

# 36. Redirect Hit Count

Jangan update `hit_count` synchronously jika traffic tinggi karena akan menyebabkan write setiap redirect.

MVP:

boleh diaktifkan optional.

Prefer:

```text
disabled by default
```

atau aggregate kemudian.

---

# 37. `notifications`

```sql
CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    user_id BIGINT UNSIGNED NOT NULL,

    event_type VARCHAR(80) NOT NULL,

    title VARCHAR(255) NOT NULL,

    message TEXT NULL,

    entity_type VARCHAR(80) NULL,

    entity_id BIGINT UNSIGNED NULL,

    read_at DATETIME NULL,

    created_at DATETIME NOT NULL,

    KEY idx_notification_user (
        site_id,
        user_id,
        read_at,
        created_at
    )
);
```

---

# 38. `notification_outbox`

Untuk email tanpa queue daemon:

```sql
CREATE TABLE notification_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    recipient VARCHAR(190) NOT NULL,

    subject VARCHAR(255) NOT NULL,

    body LONGTEXT NOT NULL,

    status VARCHAR(20) NOT NULL DEFAULT 'pending',

    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    available_at DATETIME NOT NULL,

    sent_at DATETIME NULL,

    last_error TEXT NULL,

    created_at DATETIME NOT NULL,

    KEY idx_outbox_queue (
        status,
        available_at
    )
);
```

Cron memproses batch.

---

# 39. `plugins`

Installed plugin registry.

```sql
CREATE TABLE plugins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    slug VARCHAR(120) NOT NULL,

    version VARCHAR(40) NOT NULL,

    installed_at DATETIME NOT NULL,

    UNIQUE KEY uq_plugin_slug (
        slug
    )
);
```

---

# 40. `site_plugins`

```sql
CREATE TABLE site_plugins (
    site_id BIGINT UNSIGNED NOT NULL,

    plugin_id BIGINT UNSIGNED NOT NULL,

    enabled TINYINT(1) NOT NULL DEFAULT 1,

    config LONGTEXT NULL,

    updated_at DATETIME NOT NULL,

    PRIMARY KEY (
        site_id,
        plugin_id
    )
);
```

Plugin config kecil boleh disimpan JSON.

Configuration besar harus punya table sendiri.

---

# 41. Themes

Theme file-based.

Database hanya menyimpan theme aktif pada:

```text
sites.active_theme
```

Jika butuh per-theme setting:

```sql
CREATE TABLE theme_settings (
    site_id BIGINT UNSIGNED NOT NULL,

    theme_slug VARCHAR(120) NOT NULL,

    setting_key VARCHAR(120) NOT NULL,

    setting_value LONGTEXT NULL,

    PRIMARY KEY (
        site_id,
        theme_slug,
        setting_key
    )
);
```

---

# 42. `ads`

```sql
CREATE TABLE ads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(150) NOT NULL,

    provider VARCHAR(50) NULL,

    code LONGTEXT NOT NULL,

    status VARCHAR(20) NOT NULL DEFAULT 'active',

    starts_at DATETIME NULL,
    ends_at DATETIME NULL,

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    KEY idx_ads_active (
        site_id,
        status,
        starts_at,
        ends_at
    )
);
```

---

# 43. `ad_rules`

```sql
CREATE TABLE ad_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    ad_id BIGINT UNSIGNED NOT NULL,

    placement VARCHAR(100) NOT NULL,

    content_type VARCHAR(80) NULL,

    term_id BIGINT UNSIGNED NULL,

    device VARCHAR(20) NULL,

    priority INT NOT NULL DEFAULT 10,

    enabled TINYINT(1) NOT NULL DEFAULT 1,

    KEY idx_ad_rules_lookup (
        site_id,
        placement,
        enabled,
        priority
    )
);
```

Device:

```text
desktop
mobile
all
```

`NULL` berarti all.

---

# 44. Search

Preferred:

FULLTEXT index pada:

```text
title
excerpt
content_plain
```

Contoh jika supported:

```sql
ALTER TABLE contents
ADD FULLTEXT KEY ft_content_search (
    title,
    excerpt,
    content_plain
);
```

Namun implementation harus melakukan capability detection.

---

# 45. Search Fallback

Jika FULLTEXT tidak tersedia:

prioritaskan pencarian judul.

Contoh:

```sql
WHERE site_id = ?
AND status = 'published'
AND title LIKE ?
```

Jangan otomatis melakukan wildcard scan terhadap semua LONGTEXT.

---

# 46. Search Index Future Option

Jika data tumbuh jauh lebih besar, search bisa dipindahkan ke optional plugin/service.

Core interface tetap:

```text
SearchServiceInterface
```

sehingga database schema tidak perlu dirombak.

---

# 47. `content_type_registry`

Custom post type tidak wajib disimpan database.

Core/plugin dapat register saat bootstrap.

Namun admin-created content type di masa depan dapat menggunakan:

```sql
CREATE TABLE content_type_definitions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    type_key VARCHAR(80) NOT NULL,

    definition_json LONGTEXT NOT NULL,

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    UNIQUE KEY uq_content_type (
        site_id,
        type_key
    )
);
```

Untuk MVP, table ini optional.

---

# 48. `migration_maps`

Untuk WordPress migration.

```sql
CREATE TABLE migration_maps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    source_system VARCHAR(50) NOT NULL,

    source_type VARCHAR(80) NOT NULL,

    source_id VARCHAR(120) NOT NULL,

    target_type VARCHAR(80) NOT NULL,

    target_id BIGINT UNSIGNED NOT NULL,

    created_at DATETIME NOT NULL,

    UNIQUE KEY uq_migration_source (
        site_id,
        source_system,
        source_type,
        source_id
    )
);
```

Memungkinkan migration resume/idempotent.

---

# 49. `migration_logs`

```sql
CREATE TABLE migration_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    severity VARCHAR(20) NOT NULL,

    source_type VARCHAR(80) NULL,

    source_id VARCHAR(120) NULL,

    message TEXT NOT NULL,

    created_at DATETIME NOT NULL,

    KEY idx_migration_logs (
        site_id,
        severity,
        created_at
    )
);
```

Severity:

```text
info
warning
error
```

---

# 50. `migrations`

Schema migration tracking.

```sql
CREATE TABLE migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    migration VARCHAR(255) NOT NULL,

    batch INT UNSIGNED NOT NULL,

    executed_at DATETIME NOT NULL,

    UNIQUE KEY uq_migration (
        migration
    )
);
```

---

# 51. Plugin Migration Tracking

Recommended:

```sql
CREATE TABLE plugin_migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    plugin_slug VARCHAR(120) NOT NULL,

    migration VARCHAR(255) NOT NULL,

    batch INT UNSIGNED NOT NULL,

    executed_at DATETIME NOT NULL,

    UNIQUE KEY uq_plugin_migration (
        plugin_slug,
        migration
    )
);
```

---

# 52. Sessions

Jika PHP file sessions cukup stabil di hosting:

```text
filesystem session
```

lebih disukai dibanding database session.

Location:

```text
/storage/sessions
```

Tidak perlu `sessions` table untuk MVP.

---

# 53. Cache Storage

Tidak membuat database cache table.

Gunakan:

```text
/storage/cache
```

Database tidak boleh dijadikan cache karena tujuan kita justru mengurangi database access.

---

# 54. Cache Dependency Storage

Untuk trafik awal, dependency map dapat file-based.

Contoh:

```text
/storage/cache/dependencies/content-891.json
```

Tidak perlu database table.

---

# 55. Optional `content_relations`

Untuk related content manual/plugin.

```sql
CREATE TABLE content_relations (
    site_id BIGINT UNSIGNED NOT NULL,

    source_content_id BIGINT UNSIGNED NOT NULL,

    target_content_id BIGINT UNSIGNED NOT NULL,

    relation_type VARCHAR(80) NOT NULL,

    sort_order INT NOT NULL DEFAULT 0,

    PRIMARY KEY (
        source_content_id,
        target_content_id,
        relation_type
    ),

    KEY idx_relation_reverse (
        site_id,
        target_content_id,
        relation_type
    )
);
```

Optional v1.

---

# 56. Schema Relationship — Site

```text
sites
 │
 ├──── site_domains
 │
 ├──── site_settings
 │
 ├──── users
 │
 ├──── contents
 │
 ├──── taxonomies
 │
 ├──── terms
 │
 ├──── media
 │
 ├──── comments
 │
 ├──── redirects
 │
 ├──── notifications
 │
 ├──── ads
 │
 └──── site_plugins
```

Semua tenant data memiliki `site_id`.

---

# 57. Schema Relationship — Content

```text
contents
 │
 ├──── content_revisions
 │
 ├──── content_autosaves
 │
 ├──── content_seo
 │
 ├──── content_terms
 │          │
 │          └──── terms
 │
 ├──── comments
 │
 ├──── workflow_logs
 │
 └──── featured_media_id
            │
            ▼
          media
```

---

# 58. Schema Relationship — Identity

```text
users
 │
 ├──── user_roles
 │        │
 │        ▼
 │      roles
 │        │
 │        ▼
 │  role_capabilities
 │        │
 │        ▼
 │  capabilities
 │
 ├──── contents
 ├──── media
 ├──── workflow_logs
 └──── notifications
```

---

# 59. Deletion Policy

## Site Delete

Site hard-delete tidak dilakukan sebagai operasi normal.

Gunakan:

```text
status = inactive
```

atau:

```text
suspended
```

Full purge merupakan maintenance operation.

---

# 60. Content Delete

Normal:

```text
status = trash
```

Hard delete:

```text
explicit purge
```

Saat purge, hapus:

```text
content_revisions
content_autosaves
content_seo
content_terms
comments jika configured
workflow_logs sesuai retention
```

---

# 61. User Delete

Default:

```text
status = disabled
```

Jika user dihapus permanen, content MUST NOT otomatis hilang.

Options:

```text
reassign content
```

ke user lain sebelum deletion.

---

# 62. Term Delete

Harus:

```text
remove content_terms relation
invalidate related archives
update cached counts
```

Content tidak dihapus.

---

# 63. Media Delete

Sebelum permanent delete:

cek apakah media digunakan sebagai:

```text
featured image
SEO OG image
block image
```

MVP setidaknya harus mendeteksi featured/SEO references.

---

# 64. Unique Slug Strategy

Content slug unique berdasarkan:

```text
site_id
type
slug
```

Contoh:

Site 1:

```text
post / berita-baru
page / berita-baru
```

secara database boleh ada jika routing membedakan.

Jika routing tidak membedakan type, application harus menerapkan global URL uniqueness.

---

# 65. Recommended URL Collision Strategy

Karena frontend biasanya memakai:

```text
/{slug}
```

atau:

```text
/{category}/{slug}
```

CMS harus mempunyai centralized URL registry/checker.

Tidak boleh hanya bergantung pada database unique constraint per type.

---

# 66. Optional `url_registry`

Jika URL architecture berkembang kompleks:

```sql
CREATE TABLE url_registry (
    site_id BIGINT UNSIGNED NOT NULL,

    path VARCHAR(500) NOT NULL,

    entity_type VARCHAR(80) NOT NULL,

    entity_id BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (
        site_id,
        path
    )
);
```

Ini direkomendasikan jika:

```text
pages
posts
custom post types
taxonomy
```

semuanya bisa berbagi namespace URL.

---

# 67. Recommendation: Use URL Registry

Untuk proyek ini, gunakan `url_registry`.

Alasannya:

- centralized permalink collision;
- faster route resolution;
- custom post type support;
- old permalink management lebih aman;
- multisite URL namespace jelas.

---

# 68. `url_registry`

Recommended final:

```sql
CREATE TABLE url_registry (
    site_id BIGINT UNSIGNED NOT NULL,

    path VARCHAR(500) NOT NULL,

    entity_type VARCHAR(80) NOT NULL,

    entity_id BIGINT UNSIGNED NOT NULL,

    canonical TINYINT(1) NOT NULL DEFAULT 1,

    updated_at DATETIME NOT NULL,

    PRIMARY KEY (
        site_id,
        path
    ),

    KEY idx_url_entity (
        site_id,
        entity_type,
        entity_id
    )
);
```

Example:

```text
/teknologi/openai-terbaru
→ content:891

/category/teknologi
→ term:42
```

---

# 69. Public Request Resolution

Possible flow:

```text
REQUEST PATH
     ↓
Page Cache
     ↓ MISS
URL Registry
     ↓
entity_type/entity_id
     ↓
Repository
     ↓
Renderer
```

Ini lebih efisien daripada mencoba banyak query:

```text
is page?
is post?
is category?
is tag?
```

---

# 70. Route Registry Caching

`url_registry` lookup tetap bisa disimpan dalam filesystem cache.

Hot URL:

```text
url:{site_id}:{path}
```

---

# 71. Data Type Guidelines

Short names:

```text
VARCHAR
```

Large structured content:

```text
LONGTEXT
```

JSON:

Gunakan `LONGTEXT` untuk compatibility maksimal.

Application melakukan JSON validation.

Jika database mendukung native JSON dengan baik, migration dapat memilih JSON setelah compatibility decision dibuat.

---

# 72. Monetary Data

Jika plugin payment ditambahkan di masa depan:

Jangan gunakan:

```text
FLOAT
```

gunakan:

```text
DECIMAL
```

Tidak relevan untuk CMS core saat ini.

---

# 73. Boolean

Gunakan:

```sql
TINYINT(1)
```

untuk compatibility MySQL/MariaDB.

---

# 74. Status Fields

Gunakan:

```text
VARCHAR
```

daripada database ENUM agar schema lebih extensible.

Application layer memvalidasi allowed values.

---

# 75. Foreign Key Strategy

Recommended:

gunakan FK jika environment mendukung InnoDB secara normal.

Tetapi aplikasi tidak boleh bergantung sepenuhnya pada FK.

Repositories/services tetap harus menjaga invariant.

---

# 76. High Traffic Queries

Query yang wajib dioptimasi sejak awal:

```text
homepage latest posts
single article
category archive
tag archive
author archive
search
scheduled content lookup
domain resolution
redirect lookup
API article collection
```

---

# 77. Homepage Query Budget

DB cold/cache miss:

Target:

```text
1 content query
1 batched author query
1 batched media query
1 batched taxonomy query
```

Maximum preferred:

```text
4–5
```

Setelah full page cache:

```text
0
```

---

# 78. Single Article Query Budget

Suggested:

```text
1 content
1 author
1 terms
1 media/related references
1 SEO
```

Maximum:

```text
5
```

Service MAY combine some queries.

---

# 79. Avoid `COUNT(*)`

Pagination total dapat menjadi mahal ketika content sangat besar.

MVP masih boleh menggunakan total count.

Namun archive frontend sebaiknya mendukung:

```text
has_more
```

tanpa selalu membutuhkan total exact count.

Admin pagination tetap dapat memakai count.

---

# 80. Counter Fields

Precompute jika sering digunakan:

```text
contents.comment_count
terms.content_count
```

Future optional:

```text
content_view_count
```

tidak disarankan di core karena analytics menggunakan GA/Matomo.

---

# 81. No View Counter Per Request

Core MUST NOT:

```sql
UPDATE contents
SET views = views + 1
```

setiap page view.

Ini menciptakan database write hotspot.

Gunakan Google Analytics / Matomo.

---

# 82. Settings Query Rule

Forbidden:

```text
query site_settings setiap kali membutuhkan satu setting
```

Instead:

```text
load autoload settings once
↓
cache
↓
SettingsService
```

---

# 83. Plugin Table Prefix

Plugin-specific tables:

```text
plugin_{plugin_slug}_{table}
```

atau namespace yang sudah ditentukan.

Contoh:

```text
plugin_newsletter_subscribers
```

Plugin tidak boleh membuat nama generik:

```text
data
items
settings2
```

---

# 84. Plugin Schema Rule

Plugin migration boleh:

- membuat table plugin sendiri;
- menambah capability;
- register content type;
- register taxonomy.

Plugin sebaiknya tidak:

- menambah column pada `contents`;
- menambah column pada `users`;
- mengubah existing core index.

Gunakan extension tables jika butuh data khusus.

---

# 85. Example Extension Table

Plugin review:

```sql
CREATE TABLE plugin_reviews_data (
    content_id BIGINT UNSIGNED PRIMARY KEY,

    site_id BIGINT UNSIGNED NOT NULL,

    rating DECIMAL(3,1) NULL,

    pros TEXT NULL,

    cons TEXT NULL
);
```

Lebih baik daripada menambahkan:

```text
rating
pros
cons
```

ke semua row `contents`.

---

# 86. Transaction Boundaries

Content publishing transaction:

```text
BEGIN

contents update
content_seo save
content_terms update
revision create
workflow_log insert
url_registry update

COMMIT
```

Setelah commit:

```text
dispatch content.published
```

Kemudian:

```text
cache invalidation
sitemap invalidation
notification
plugin hooks
```

---

# 87. Deadlock Prevention

Services harus:

- update tabel dalam urutan konsisten;
- transaction singkat;
- tidak menjalankan email di transaction;
- tidak menjalankan image processing berat di transaction;
- tidak menjalankan plugin side-effect dalam transaction.

---

# 88. Media Processing Transaction

Upload:

```text
validate file
↓
write temp
↓
process variants
↓
DB transaction
↓
create media records
↓
move/confirm files
```

Cleanup diperlukan jika database save gagal.

---

# 89. Sitemap Storage

Tidak perlu table sitemap.

Generated files:

```text
/storage/cache/sitemap/
```

Database hanya menjadi source data.

---

# 90. Cache Storage

Tidak ada:

```text
cache table
```

Full page:

```text
/storage/cache/pages/
```

Objects:

```text
/storage/cache/objects/
```

Generated:

```text
/storage/cache/generated/
```

---

# 91. Audit Requirements

Editorial workflow harus memiliki audit.

Plugin activation/deactivation sebaiknya di-log file.

Administrative full audit log MAY ditambahkan kemudian.

Tidak perlu menyimpan setiap admin click ke database.

---

# 92. Database Growth Estimate

Dengan:

```text
30 posts/day
```

annual:

```text
~10,950 posts/year
```

5 tahun:

```text
~54,750 posts
```

Ini masih kecil untuk MySQL/MariaDB jika indexing benar.

Database bottleneck lebih mungkin datang dari:

```text
bad query patterns
metadata joins
uncached requests
N+1
```

daripada jumlah artikel itu sendiri.

---

# 93. Revision Growth

Dengan max 3 revision:

```text
54,750 contents
× max 3
≈ 164,250 revisions
```

masih sangat manageable.

Lebih aman dibanding unlimited revisions.

---

# 94. Comment Growth

Comments berpotensi tumbuh lebih cepat daripada content.

Karena itu index:

```text
(site_id, content_id, status, created_at)
```

wajib ada.

---

# 95. Migration From WordPress

Mapping:

```text
wp_posts
→ contents
```

```text
wp_users
→ users
```

```text
wp_terms
wp_term_taxonomy
wp_term_relationships
→ taxonomies
→ terms
→ content_terms
```

```text
wp_comments
→ comments
```

```text
wp_postmeta Yoast
→ content_seo
```

```text
attachments
→ media
```

---

# 96. WordPress `postmeta`

Importer membaca postmeta, tetapi hanya known mappings yang masuk core structures.

Examples:

```text
_thumbnail_id
→ featured_media_id

_wpseo_title
→ content_seo.meta_title

_wpseo_metadesc
→ content_seo.meta_description
```

Unknown fields dapat masuk:

```text
entity_meta
```

jika migration configuration memilih preserve.

---

# 97. WordPress URL Migration

Old URL:

```text
/news/2024/article
```

Jika CMS menghasilkan URL sama:

tidak perlu redirect.

Jika berubah:

```text
redirects
```

mendapat row:

```text
/news/2024/article
→ /news/article
301
```

---

# 98. Schema Migration Ordering

Recommended:

```text
001 sites
002 site_domains
003 settings
004 users
005 roles
006 capabilities
007 contents
008 revisions
009 taxonomies
010 terms
011 content_terms
012 media
013 seo
014 comments
015 workflow
016 redirects
017 url_registry
018 plugins
019 notifications
020 advertising
021 migration support
```

---

# 99. Agent Ownership

## Database/Core Agent

Owns:

```text
migration runner
common schema conventions
migrations table
database connection
transaction manager
```

## Multisite Agent

Owns:

```text
sites
site_domains
site_settings
```

## Identity Agent

Owns:

```text
users
roles
capabilities
user_roles
role_capabilities
password_resets
```

## Content Agent

Owns:

```text
contents
content_autosaves
url_registry content integration
```

## Revision Agent

Owns:

```text
content_revisions
```

## Taxonomy Agent

Owns:

```text
taxonomies
terms
content_terms
```

## Media Agent

Owns:

```text
media
media_variants
```

## SEO Agent

Owns:

```text
content_seo
redirects
```

## Workflow Agent

Owns:

```text
workflow_logs
```

## Comment Agent

Owns:

```text
comments
```

## Notification Agent

Owns:

```text
notifications
notification_outbox
```

## Plugin Agent

Owns:

```text
plugins
site_plugins
plugin_migrations
```

## Advertising Agent

Owns:

```text
ads
ad_rules
```

## Migration Agent

Owns:

```text
migration_maps
migration_logs
```

---

# 100. Cross-Agent Database Rule

Agent MUST NOT modify tables owned by another agent without:

```text
architecture approval
+
migration coordination
```

Jika membutuhkan data domain lain:

gunakan:

```text
service
repository interface
event
```

bukan direct cross-domain SQL.

---

# 101. Schema Freeze v1

Sebelum parallel coding, table berikut dianggap baseline frozen:

```text
sites
site_domains
site_settings

users
roles
capabilities
role_capabilities
user_roles

contents
content_revisions
content_autosaves

taxonomies
terms
content_terms

content_seo

media
media_variants

comments

workflow_logs

redirects
url_registry

plugins
site_plugins

notifications
notification_outbox

ads
ad_rules

migrations
plugin_migrations

migration_maps
migration_logs
```

---

# 102. Schema Changes After Freeze

Perubahan yang:

```text
rename column
drop column
change datatype
change unique constraint
change ownership
```

dianggap breaking.

Harus disertai:

```text
ADR
migration
impact analysis
updated contract
```

---

# 103. Required Database Tests

Minimal:

### Multisite Isolation

```text
site A repository tidak bisa membaca row site B
```

### Slug Collision

```text
duplicate slug pada site/type yang sama ditolak
```

### Revision Limit

```text
max 3
```

### Workflow

```text
transition menghasilkan workflow log
```

### Term Count

```text
attach/detach memperbarui content_count
```

### Comment Count

```text
approval memperbarui comment_count
```

### URL Registry

```text
duplicate site/path ditolak
```

### Migration Map

```text
same source object tidak menghasilkan duplicate import
```

---

# 104. Required Performance Tests

Seed:

```text
50,000 contents
100,000 terms relations
100,000 comments
```

Test:

```text
latest articles
single slug lookup
category archive
author archive
scheduled post scan
redirect lookup
URL lookup
search
```

Setiap query harus menggunakan index yang dapat diverifikasi melalui:

```sql
EXPLAIN
```

---

# 105. EXPLAIN Requirement

Agent yang menambah high-frequency query harus mencantumkan:

```text
SQL
expected index
EXPLAIN result
```

dalam implementation note/test documentation.

---

# 106. Database Metrics Development Mode

Development toolbar/log SHOULD menampilkan:

```text
query count
total query duration
slowest query
duplicate queries
```

untuk mendeteksi pola WordPress-style repeated queries sejak awal.

---

# 107. Slow Query Threshold

Development warning:

```text
> 100ms
```

sebagai default awal.

Bukan production SLA absolut.

---

# 108. Final Recommended ERD

```text
┌──────────────────┐
│      sites       │
└────────┬─────────┘
         │
         ├──────────────┐
         │              │
         ▼              ▼
┌──────────────┐ ┌──────────────┐
│ site_domains │ │site_settings │
└──────────────┘ └──────────────┘

         │
         ├────────────────────────────┐
         │                            │
         ▼                            ▼
┌──────────────────┐         ┌──────────────────┐
│      users       │         │     contents     │
└────────┬─────────┘         └────────┬─────────┘
         │                            │
    user_roles                       ├── revisions
         │                            ├── autosaves
         ▼                            ├── seo
      roles                           ├── comments
         │                            ├── workflow_logs
role_capabilities                     ├── content_terms
         │                            │        │
         ▼                            │        ▼
   capabilities                       │      terms
                                      │        │
                                      │   taxonomies
                                      │
                                      └── featured_media
                                               │
                                               ▼
                                             media
                                               │
                                               ▼
                                         media_variants


sites
 │
 ├── redirects
 ├── url_registry
 ├── notifications
 ├── notification_outbox
 ├── site_plugins
 ├── ads
 └── ad_rules
```

---

# 109. Critical Database Decisions

Keputusan v1:

**1. No WordPress-style core meta model**

Core fields memiliki table/column sendiri.

**2. Content source + compiled output**

```text
content_json
content_html
content_plain
```

**3. Site isolation everywhere**

Setiap tenant-owned row memiliki:

```text
site_id
```

**4. Filesystem caching**

Database tidak dijadikan cache store.

**5. URL registry**

Routing publik tidak mencoba query banyak entity type secara berurutan.

**6. Precomputed counters**

Gunakan:

```text
comment_count
content_count
```

daripada hitung berulang.

**7. Maximum 3 revisions**

Database growth predictable.

**8. No per-view database writes**

Analytics menggunakan GA/Matomo.

---

# 110. Database North Star

Public visitor pada cache HIT:

```text
Visitor
   ↓
Domain Cache
   ↓
Page Cache
   ↓
HTML

Database queries: 0
```

Pada cache MISS:

```text
Visitor
   ↓
URL Registry
   ↓
Content query
   ↓
Batched relations
   ↓
Render
   ↓
Cache
```

Bukan:

```text
Visitor
   ↓
query post
query post meta
query author
query author meta
query category
query tag
query image
query options
query plugins
query related posts one by one
...
```

Itulah database architecture utama yang harus dipertahankan semua agent.

---

# 111. Database Specification Definition of Done

Database architecture dianggap siap ketika:

- seluruh baseline migrations tersedia;
- indexes tersedia;
- repository menggunakan SiteContext;
- URL registry berjalan;
- revision limit diuji;
- taxonomy relation diuji;
- role/capability bekerja;
- content publish transaction bekerja;
- WordPress migration mapping mempunyai target jelas;
- query budget tests tersedia;
- `EXPLAIN` untuk high-traffic queries tidak menunjukkan unintended full table scan;
- 50k content test dataset tetap dapat digunakan dengan performa wajar;
- cached frontend dapat berjalan tanpa database query.

---

# 112. Next Contract

Setelah database schema ini dibekukan, artifact berikutnya adalah:

**REST API & Internal Service Contract Specification**

Dokumen tersebut harus mendefinisikan endpoint, request DTO, response DTO, permissions, validation, error codes, event side-effects, serta service method yang akan digunakan oleh Admin UI, frontend submission, theme, plugin, dan migration agent.

Setelah API/service contract selesai, sistem sudah cukup stabil untuk dibuatkan **Agent Task Pack** dan pembangunan paralel dimulai.