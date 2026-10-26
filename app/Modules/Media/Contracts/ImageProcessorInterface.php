<?php

declare(strict_types=1);

namespace CMS\Modules\Media\Contracts;

/**
 * Image transformation backend.
 *
 * The engine detects capabilities at runtime: Imagick is preferred, GD is the
 * supported fallback. A caller must be able to ask what is available rather than
 * assuming a library exists, because shared hosting frequently ships one and not
 * the other.
 *
 * FROZEN CONTRACT.
 */
interface ImageProcessorInterface
{
    /**
     * Human-readable backend name: `imagick`, `gd`, or `none`.
     */
    public function driver(): string;

    public function isAvailable(): bool;

    /**
     * Can this backend emit the given format (e.g. `webp`, `jpeg`)?
     */
    public function supportsFormat(string $format): bool;

    /**
     * Read image dimensions without decoding the whole file.
     *
     * @return array{width: int, height: int, mime: string}|null
     */
    public function inspect(string $absolutePath): ?array;

    /**
     * Produce a resized/compressed copy at the destination path.
     *
     * When `$maxWidth`/`$maxHeight` are given the image is scaled down to fit
     * inside them, preserving aspect ratio, and never scaled up.
     *
     * @param string $format One of `jpeg`, `png`, `webp`, or `original`.
     *
     * @return array{width: int, height: int, filesize: int}|null
     */
    public function transform(
        string $sourcePath,
        string $destinationPath,
        string $format,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        int $quality = 82,
    ): ?array;

    /**
     * Remove EXIF metadata by re-encoding the image in place where supported.
     *
     * Returns false when the backend cannot do it; callers must treat metadata
     * stripping as best-effort rather than assume success.
     */
    public function stripMetadata(string $absolutePath): bool;
}
