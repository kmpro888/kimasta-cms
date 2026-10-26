# Media Module — Media Engine

Owns uploads, derived image variants and the media library.

## Ownership

Tables: `media`, `media_variants`. Filesystem: `public/uploads/`.

## Contracts provided

| Contract | Implementation |
|---|---|
| `Contracts\MediaServiceInterface` | `Service\MediaService` |
| `Contracts\MediaRepositoryInterface` | `Repository\PdoMediaRepository` |
| `Contracts\ImageProcessorInterface` | `Image\ImageProcessor` |
| `Contracts\MediaUsageCheckerInterface` | `Support\NullMediaUsageChecker` (default) |

## Upload pipeline (order is load-bearing)

```text
PHP upload state → size limit → filename sanity → extension whitelist
→ real MIME inspection → generated filename → store
→ strip metadata → derive variants (original format + WebP) → persist rows
```

Validation runs against the **inspected** MIME type, never the client-supplied
one, and the extension written to disk is derived from that inspection rather
than from the uploaded filename. Those two rules together remove the classic
`shell.php.jpg` bypass: either the bytes are image bytes or the upload is
rejected, and the on-disk name is generated.

## Storage layout

```text
public/uploads/{site_id}/{year}/{month}/{slug}-{random}.{ext}
```

Site-scoped so two tenants can upload the same filename without colliding. The
database stores the site-relative path, never an absolute URL, so a domain change
needs no row rewrite.

## Variants

`thumbnail` 150, `medium` 640, `large` 1280 (fit inside the box, never upscaled),
emitted in the source format and in WebP **only when the backend can encode it**.

## Image backend

Imagick is preferred, GD is the supported fallback; the driver is detected at
runtime and reported by `driver()`. On this host GD is active with WebP support,
so the fallback path is the one exercised by the test suite.

## Deleting referenced media

Media does not query `contents`. It asks
`MediaUsageCheckerInterface::countReferences()` and refuses to delete while the
count is non-zero unless `force` is passed. The Content module registers the real
checker; this module keeps a permissive default so it works standalone.
