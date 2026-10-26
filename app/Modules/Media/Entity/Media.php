<?php

declare(strict_types=1);

namespace CMS\Modules\Media\Entity;

use DateTimeImmutable;

/**
 * Immutable media row.
 *
 * `path` is a site-relative path, never an absolute URL, so a domain change or a
 * CDN move never requires rewriting rows.
 */
final class Media
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $siteId,
        public readonly int $uploadedBy,
        public readonly ?string $originalName,
        public readonly string $path,
        public readonly string $mimeType,
        public readonly string $extension,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly int $filesize,
        public readonly ?string $altText,
        public readonly ?string $caption,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    /**
     * Public URL for the original file, given the site's base URL.
     */
    public function url(string $baseUrl = ''): string
    {
        return rtrim($baseUrl, '/') . '/uploads/' . ltrim($this->path, '/');
    }
}
