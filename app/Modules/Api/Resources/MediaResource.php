<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Resources;

use CMS\Modules\Media\Entity\Media;

/**
 * Explicit media projection.
 *
 * `path` is the storage-relative path; the absolute filesystem location is never
 * exposed, because an API response must not disclose server layout.
 */
final class MediaResource implements ResourceInterface
{
    public function __construct(private readonly string $baseUrl = '') {
    }

    /**
     * @param Media $subject
     *
     * @return array<string, mixed>
     */
    public function toArray(mixed $subject): array
    {
        if (!$subject instanceof Media) {
            throw new \InvalidArgumentException('MediaResource expects a Media entity.');
        }

        return [
            'id' => $subject->id,
            'original_name' => $subject->originalName,
            'path' => $subject->path,
            'url' => $subject->url($this->baseUrl),
            'mime_type' => $subject->mimeType,
            'extension' => $subject->extension,
            'is_image' => $subject->isImage(),
            'width' => $subject->width,
            'height' => $subject->height,
            'filesize' => $subject->filesize,
            'alt_text' => $subject->altText,
            'caption' => $subject->caption,
            'uploaded_by' => $subject->uploadedBy,
            'created_at' => $subject->createdAt->format(DATE_ATOM),
            'updated_at' => $subject->updatedAt->format(DATE_ATOM),
        ];
    }
}
