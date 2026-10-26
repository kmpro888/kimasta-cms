# Migration Module — WordPress Import

## Ownership

Tables: `migration_maps`, `migration_logs`.
Source: a **separate** WordPress database (`wp_posts`, `wp_postmeta`, `wp_users`,
`wp_terms`, `wp_term_taxonomy`, `wp_term_relationships`, `wp_comments`, `wp_options`).

## Why the source is a separate PDO

The WordPress database is foreign, legacy and untrusted. Reading it through the platform's
own `Connection` would blur the boundary between our data and someone else's, and would let
a migration query run inside our transaction.

If `migration.source_dsn` is empty the source falls back to the platform connection with a
configurable prefix — the common case when both live in one database during a cutover.

## Process

```text
analyze → plan → batch import → mapping → validation → report
```

| Step | Command |
|---|---|
| analyze | `php bin/migrate-wp.php analyze <site-slug>` |
| plan | `php bin/migrate-wp.php plan <site-slug>` |
| batch | `php bin/migrate-wp.php batch <site-slug> <type> [limit] [after]` |
| full run | `php bin/migrate-wp.php run <site-slug> [batchSize]` |
| report | `php bin/migrate-wp.php report <site-slug>` |
| logs | `php bin/migrate-wp.php logs <site-slug> [level]` |

The CLI is the normal runner because a full import exceeds any shared-host request timeout.
The API exposes inspection and a single batch, never a full run.

## Import order

`user → term → media → post → page → comment`

Not cosmetic: content needs a migrated author, media needs an uploader and its id is the
featured image, comments need their post. Importing out of order fails loudly rather than
producing half-attributed rows.

## Idempotency

`migration_maps` is unique on `(site_id, source_type, source_id)`. A rerun skips every row
already mapped, so a batch that timed out can simply be run again.

The skip check and the recorder use **the same key function**. When they disagreed — skip
looking up `post:` while the importer recorded `post` — a rerun duplicated every row. That
bug existed, the test caught it, and the key is now derived in one place.

## Batches use a keyset cursor

`runBatch()` returns `next_after`, the last source id processed. Resuming uses
`source_id > :after`, not `OFFSET`, so the cursor stays correct even if source rows are
added mid-migration.

## Mapping

| WordPress | Target |
|---|---|
| `post_title` / `post_name` / `post_excerpt` | `contents` |
| `post_content` (HTML) | a single `html` block, byte for byte |
| `post_date_gmt` | `published_at` |
| `post_status` = `publish` | `published`; anything else stays `draft` |
| `post_author` | mapped CMS user |
| `_thumbnail_id` | `featured_media_id` (through the media map) |
| categories / tags | `category` / `tag` taxonomies, attached with `sync()` |
| `_yoast_wpseo_title` / `_wpseo_title` | `content_seo.meta_title` |
| `_yoast_wpseo_metadesc` / `_wpseo_metadesc` | `content_seo.meta_description` |
| `_yoast_wpseo_canonical` / `_wpseo_canonical` | `content_seo.canonical_url` (absolute only) |
| `_yoast_wpseo_meta-robots-noindex` = 1 | `robots_index = false` |

The body is preserved as raw HTML rather than split into paragraph blocks: WordPress content
is HTML, the platform has a raw-HTML block for exactly this, and re-parsing would lose
markup nobody asked us to rewrite.

## URL preservation

The WordPress `permalink_structure` option is read and compared against the paths this
platform produces:

- equal → **the URL is preserved**, no redirect;
- different → a **301 redirect** from the old path to the new one.

A broken inbound link is a permanent SEO loss, so the redirect is created eagerly rather
than left for an operator to notice.

## Unknown metadata

Every `postmeta` key outside the known set is written to `migration_logs` with the source id,
the field and the reason. Nothing is discarded silently — the task pack forbids it, and a
silent drop is indistinguishable from a bug.

## Things that are logged rather than guessed

| Situation | Behaviour |
|---|---|
| WordPress password hash | not portable to `password_hash()`; account created with a random password, reset logged |
| attachment file missing from the uploads path | **no media row is created** — a row with no file renders a broken image forever |
| guest comment (no account) | not imported; this platform requires an authenticated commenter |
| relative Yoast canonical | not stored; the SEO service requires an absolute URL |
| author not migrated | attributed to the site default author, logged |
| unmapped term in a relationship | relationship dropped, logged |

## Not implemented

- No WordPress multisite network import; one site per run against one target tenant.
- No `wp_postmeta` preservation table (`entity_meta`); unknown keys are logged only.
- No revisions, menus, widgets, or page-builder shortcodes.
- No media download over HTTP: files must already exist under `migration.uploads_path`.
- Comments are imported flat — `comment_parent` is read but nesting is not reconstructed.
- No progress-resume UI; the CLI prints per-batch progress.
