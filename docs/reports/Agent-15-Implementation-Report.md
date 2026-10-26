# Agent 15 — WordPress Migration — Implementation Report

Agent: Agent 15 — WordPress Migration
Module: `CMS\Modules\Migration`
Status: DONE for the single-site import described in the task pack. WordPress **multisite
networks** are out of scope and stated as such.

---

## Summary

An inspectable, resumable, idempotent WordPress import:

```text
analyze → plan → batch import → mapping → validation → report
```

Two design decisions shape everything:

1. **The source is a separate PDO.** The WordPress database is foreign and legacy; reading
   it through the platform's own connection would blur the data boundary and let a migration
   query run inside our transaction.
2. **Nothing is discarded silently.** Every skip, unmapped field and forced decision writes
   a `migration_logs` row naming the source id, the field and the reason.

The acceptance criteria are met against a synthetic WordPress dataset built in the test
database: post, page, author, category, tag, media, featured image, comment, Yoast SEO,
slug, publish date, plus a URL integrity report.

---

## Files added

```text
app/Modules/Migration/Contracts/MigrationServiceInterface.php
app/Modules/Migration/Source/WordPressSource.php
app/Modules/Migration/Repository/MigrationRepositoryInterface.php
app/Modules/Migration/Repository/PdoMigrationRepository.php
app/Modules/Migration/Service/MigrationService.php
app/Modules/Migration/MigrationServiceProvider.php
app/Modules/Migration/README.md

app/Modules/Api/Http/Controller/MigrationController.php
bin/migrate-wp.php

database/migrations/20261026001500_create_migration_tables.php

tests/Integration/Modules/Migration/WordPressMigrationTest.php
```

## Files modified

```text
app/bootstrap.php                                provider registered
app/Modules/Api/ApiServiceProvider.php           5 migration routes
tests/Support/ModuleDatabaseTestCase.php         migration tables
```

---

## Database migrations

| Migration | Tables |
|---|---|
| `20261026001500_create_migration_tables.php` | `migration_maps`, `migration_logs` |

Verified forward and rollback on both databases.

`migration_maps` is unique on `(site_id, source_type, source_id)` — that unique key **is** the
idempotency guarantee.

---

## Source support

Read through `WordPressSource`, all prefix-configurable and identifier-validated:

```text
wp_posts · wp_postmeta · wp_users · wp_terms · wp_term_taxonomy
wp_term_relationships · wp_comments · wp_options
```

The table prefix is the one place an identifier is concatenated into SQL, so it is matched
against `^[A-Za-z0-9_]{0,40}$` in the constructor.

---

## Import order

```text
user → term → media → post → page → comment
```

Not cosmetic. Content needs a migrated author; media needs an uploader and supplies the
featured image; comments need their post. A test proves the ordering is enforced by the data
rather than by the caller's good behaviour: importing posts with no users on the target
imports nothing and reports two failures.

---

## Mapping

| WordPress | Target |
|---|---|
| `post_title`, `post_name`, `post_excerpt` | `contents` |
| `post_content` (HTML) | one `html` block, byte for byte |
| `post_date_gmt` | `published_at` |
| `post_status = publish` | `published`; anything else `draft` |
| `post_author` | mapped CMS user |
| `_thumbnail_id` | `featured_media_id` via the media map |
| categories / tags | `category` / `tag`, attached with `sync()` |
| `_yoast_wpseo_title` / `_wpseo_title` | `content_seo.meta_title` |
| `_yoast_wpseo_metadesc` / `_wpseo_metadesc` | `content_seo.meta_description` |
| `_yoast_wpseo_canonical` / `_wpseo_canonical` | `content_seo.canonical_url` (absolute only) |
| `_yoast_wpseo_meta-robots-noindex` = 1 | `robots_index = false` |

**Why the body is one raw-HTML block rather than parsed paragraphs:** WordPress content is
HTML, the platform ships a raw-HTML block for exactly this, and re-parsing would lose markup
nobody asked us to rewrite. The test asserts the body round-trips byte for byte.

---

## Idempotency, and the bug that broke it

`runBatch()` skips any source row already in `migration_maps`, and reports `next_after` so the
caller resumes with a keyset cursor (`source_id > :after`) rather than `OFFSET`.

The first implementation **duplicated every row on a rerun**, and the test caught it:

```text
skip check lookup key : "post:"        (type + ':' + empty taxonomy)
importer recorded key : "post"
```

The lookup never matched, so nothing was ever skipped. Fixed by deriving the key in one
function used by both sides. The bug is worth recording because it is invisible in a
single-run test and fatal in the real workflow — a migration that times out halfway and is
re-run is the *normal* case on a shared host.

---

## URL preservation

The WordPress `permalink_structure` option is read and compared against the paths this
platform produces:

| Situation | Behaviour |
|---|---|
| paths match | URL preserved, no redirect |
| paths differ (`/archives/%postname%/` → `/blog/%postname%/`) | **301 redirect** created |

A test covers both directions and the report counts preserved vs redirected.

---

## Unknown metadata

Every `postmeta` key outside the known set is logged with source id, field and reason. A test
asserts `_my_custom_field` produces exactly one log row referencing post 1.

## Decisions logged rather than guessed

| Situation | Behaviour |
|---|---|
| WordPress password hash (phpass) | not portable — account created with a random password, reset logged |
| attachment file absent from `migration.uploads_path` | **no media row created**; a row with no file renders a broken image forever |
| guest commenter with no account | not imported; this platform requires an authenticated commenter |
| relative Yoast canonical | not stored; the SEO service requires an absolute URL |
| author not migrated | attributed to the site default author, logged |
| unmapped term in a relationship | relationship dropped, logged |

---

## Defect found: inspection commands crashed without a source

`analyze` and `report` threw a raw `PDOException: Table 'wp_users' doesn't exist` when no
source was configured. A fresh install, a wrong DSN and a wrong prefix are all normal states
*before* a migration — a tool that cannot be inspected until the source exists is not usable.

`shape()` now checks `isAvailable()` and returns zeros; the CLI prints an explicit
configuration hint. Verified:

```
$ php bin/migrate-wp.php analyze live-demo
No WordPress source found (prefix "wp_"). Configure migration.source_dsn/prefix.
  user     source=0  imported=0  pending=0
  ...

$ php bin/migrate-wp.php report live-demo
  source available: no
```

---

## Tests added

`WordPressMigrationTest` — 18 tests and one helper:

- source analysed per type, with nothing imported yet
- import order puts dependencies first
- a user is imported; the hash reset is logged
- categories and tags become terms in separate taxonomies
- an attachment becomes a media row
- a missing file is logged and **no** row is created
- a post is imported with slug, publish date, HTML body, both terms, featured image and Yoast
- a page is imported as a page
- a draft stays a draft
- an approved comment is imported; a guest commenter is logged and skipped
- unknown postmeta is logged, not discarded
- **rerunning a completed batch imports nothing**
- **an interrupted run resumes without duplicating**
- content cannot be imported before its author
- a changed permalink structure produces redirects
- a matching permalink structure preserves URLs
- the report summarises counts, logs and URL integrity
- a second site imports independently

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (1077 tests, 3302 assertions)
```

Baseline 1059 → 1077: **+18 tests**, none broken.

---

## Verified over the CLI

```
php bin/migrate-wp.php analyze live-demo    graceful zeros + config hint
php bin/migrate-wp.php report  live-demo    source available: no
php bin/migrate-wp.php nonsense live-demo   exit 2, usage message
```

The CLI boots through `app/bootstrap.php`, so it shares one wiring with HTTP and cron.

---

## Known limitations

1. **No WordPress multisite network import.** One source site per run, one target tenant.
   A network of five sites means five runs against five targets.
2. **No `entity_meta` preservation.** Unknown `postmeta` is logged, not stored — the task
   pack lists this as optional.
3. **No revisions, menus, widgets or page-builder shortcodes.** Shortcodes arrive as literal
   text inside the HTML block.
4. **No media download over HTTP.** Files must already be under `migration.uploads_path`;
   a remote uploads URL is logged and skipped.
5. **Comments are imported flat.** `comment_parent` is read but nesting is not reconstructed,
   because this platform's comment depth is bounded at 3 and a deep WordPress thread would be
   refused anyway.
6. **Category hierarchies are flattened.** `wp_term_taxonomy.parent` is not mapped.
7. **Status mapping is coarse.** `pending` and `private` both become `draft`; the original
   status is not preserved anywhere but the log.
8. **No dry-run mode.** `plan` reports counts but does not simulate individual row failures.
9. **A migration cannot be undone through the API.** `migration_maps` is the audit trail; the
   content is ordinary content and must be removed through the content endpoints.

---

## CONTRACT_CHANGE_REQUEST

none

---

## Ready to merge

yes
