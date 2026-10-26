<?php

declare(strict_types=1);

namespace CMS\Modules\Media\Entity;

/**
 * One rendered size/format of a media row.
 */
final class MediaVariant
{
    public function __construct(
        public readonly int $id,
        public readonly int $mediaId,
        public readonly int $siteId,
        public readonly string $variant,
        public readonly string $format,
        public readonly string $path,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?int $filesize,
    ) {
    }

    public function url(string $baseUrl = ''): string
    {
        return rtrim($baseUrl, '/') . '/uploads/' . ltrim($this->path, '/');
    }
}
