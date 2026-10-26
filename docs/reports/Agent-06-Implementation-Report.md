# Agent 06 — Media Engine — Implementation Report

Agent: Agent 06 — Media Engine
Module: `CMS\Modules\Media`
Status: DONE

---

## Summary

Implemented secure image upload with a strictly ordered validation pipeline, a
runtime-detected image backend (Imagick preferred, GD fallback), site-scoped
storage, derived variants including WebP when the backend supports it, and a
reference-aware delete that does not reach into another module's table.

Executed by the controller directly, for the same reason as Agent 04; see
`Wave-3-Tracking.md`.

---

## Files added

```text
app/Modules/Media/MediaServiceProvider.php
app/Modules/Media/README.md

app/Modules/Media/Contracts/MediaServiceInterface.php
app/Modules/Media/Contracts/MediaRepositoryInterface.php
app/Modules/Media/Contracts/ImageProcessorInterface.php
app/Modules/Media/Contracts/MediaUsageCheckerInterface.php

app/Modules/Media/Entity/Media.php
app/Modules/Media/Entity/MediaVariant.php
app/Modules/Media/Entity/UploadedFile.php

app/Modules/Media/Exceptions/MediaNotFoundException.php
app/Modules/Media/Exceptions/InvalidUploadException.php
app/Modules/Media/Exceptions/UnsupportedMediaTypeException.php
app/Modules/Media/Exceptions/MediaTooLargeException.php
app/Modules/Media/Exceptions/MediaInUseException.php

app/Modules/Media/Repository/PdoMediaRepository.php

app/Modules/Media/Service/MediaService.php

app/Modules/Media/Image/ImageProcessor.php

app/Modules/Media/Support/NullMediaUsageChecker.php

database/migrations/20261025000500_create_media_tables.php
```

## Files modified

```text
app/bootstrap.php                                  provider registered (after Content)
app/Modules/Content/ContentServiceProvider.php     binds the real MediaUsageChecker
docs/reports/Wave-3-Tracking.md                    controller log
```

---

## Contracts consumed

```text
CMS\Shared\Contracts\ClockInterface
CMS\Shared\Contracts\EventDispatcherInterface
CMS\Shared\Contracts\ConfigInterface
CMS\Core\Events\DomainEvent
```

## Contracts provided

`MediaServiceInterface`, `MediaRepositoryInterface`, `ImageProcessorInterface`,
`MediaUsageCheckerInterface`.

### Cross-module seam worth calling out

"Referenced media deletion safeguards" requires knowing whether `contents`
references a media row — but Media must not query the Content table. The
resolution: Media declares `MediaUsageCheckerInterface`, Content implements it
(`ContentMediaUsageChecker`, which owns `contents` and may query it), and the
container prefers the Content binding. Media keeps a permissive
`NullMediaUsageChecker` as a fallback so the module works standalone.

Verified at runtime: the container resolves
`MediaUsageCheckerInterface` → `CMS\Modules\Content\Support\ContentMediaUsageChecker`.

---

## Database migrations

| Migration | Tables |
|---|---|
| `20261025000500_create_media_tables.php` | `media`, `media_variants` |

Follows `docs/02-Database-Schema.md` §29–30. `media_variants` is unique on
`(media_id, variant, format)` so re-running the variant pipeline upserts instead
of duplicating. Verified forward and rollback.

---

## Events emitted

| Event | When |
|---|---|
| `media.created` | after the pipeline persists the row and its variants |
| `media.updated` | on alt-text/caption change |
| `media.deleted` | after rows and files are removed |

Payload carries `id`, `site_id`, `path` so a listener can invalidate a specific
asset rather than a whole directory.

## Events consumed

None.

---

## API changes

None. No routes; the admin upload endpoint belongs to a later agent.

---

## Cache impact

Media paths are immutable once written (the filename is generated and a rename is
not supported), so an uploaded asset can be cached aggressively by the web server.
`media.deleted` is the invalidation signal.

Deriving variants at upload time rather than on first request means a page view
never triggers image processing — which matters on shared hosting where a
synchronous GD resize can take a noticeable fraction of a second.

---

## Multisite impact

- Storage path is `uploads/{site_id}/{year}/{month}/`, so two tenants can upload
  the same filename without colliding.
- Every repository statement carries `site_id`, including DELETE.
- `listForSite`, `get`, `update` and `delete` are all site-scoped; a media id from
  another site resolves to `MEDIA_NOT_FOUND`.
- A test asserts the resolved absolute path always stays inside the uploads root.

---

## Security impact

| Risk | Control |
|---|---|
| Executable upload | Extension whitelist (`jpg`, `jpeg`, `png`, `webp`) **and** real MIME inspection; both must agree |
| `.php.jpg` double extension | Rejected before inspection |
| Client MIME spoofing | Client type is stored for reference only; the decision uses `finfo` |
| Path traversal in filename | On-disk name is generated (`{slug}-{12 hex}.{ext}`), never the client's |
| Metadata leakage (GPS, camera) | `stripMetadata()` after store; GD re-encode drops it inherently |
| Oversized upload | Byte limit enforced before any processing |
| Broken referenced page | Delete refuses while `countReferences() > 0` without `force` |
| Transparent image corruption | Alpha channel preserved for PNG/WebP |

A test asserts no stored path ever contains `.php` or `..`, and that the final
absolute path is inside the uploads root.

---

## Tests added

- unit: `ImageProcessorTest` (12), `UploadedFileTest` (8)
- integration: `MediaServiceTest` (23)
- contract: `MediaContractTest` (4)
- security: `MediaUploadSecurityTest` (13)
- performance: none specific to this module (upload is not a hot read path)

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (468 tests, 1314 assertions)
```

Media contributes 60 of those.

---

## Environment findings

Detected on this host, and the reason the fallback path is genuinely exercised
rather than assumed:

```text
gd=yes   imagick=no   exif=yes   fileinfo=yes
gd webp=yes   gd jpeg=yes   gd png=yes
```

`ImageProcessor::driver()` therefore reports `gd`, and the WebP generation test
runs for real instead of skipping.

---

## Query/performance notes

- Upload is write-path: one insert for `media`, one upsert per variant.
- `listBySite` is index-backed on `(site_id, created_at)` and returns a bounded
  page; no `SELECT *`.
- Variant rows carry `site_id` so a variant lookup needs no join to `media`.
- File deletion happens only after the row delete succeeds: an orphan file is
  harmless and recoverable, a row pointing at a missing file is a broken page.

---

## Known limitations

1. **Animated GIF and PDF are not supported.** The config's
   `allowed_mime_types` lists them, but the pipeline handles raster images only.
   Supporting GIF would need animation preservation and PDF would need a completely
   different variant strategy, so both are refused with
   `INVALID_MEDIA_TYPE` rather than half-handled. This is a deliberate scope
   boundary, not an oversight.
2. Variant sizes are class constants, not config. Making them site-configurable
   needs the settings service wired into admin forms (later agent).
3. No CDN or remote storage driver; files land on the local disk, which suits the
   shared-hosting target.
4. EXIF stripping is best-effort by contract. With GD it is inherent (GD encoders
   write no EXIF); with a restricted Imagick build it may report false.
5. No image deduplication — two uploads of identical bytes produce two files.

---

## CONTRACT_CHANGE_REQUEST

none

No existing frozen contract was modified. The four Media interfaces are new in
this wave and now frozen.

---

## Ready to merge

yes
