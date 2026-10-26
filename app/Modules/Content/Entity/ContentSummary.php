<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Entity;

use DateTimeImmutable;

/**
 * Listing projection of a content row.
 *
 * A homepage or archive renders dozens of cards and needs none of the block
 * payload. Having a separate type makes that explicit: the repository simply
 * cannot return `content_json` here, so a heavy field can never leak into a
 * listing query by accident.
 */
final class ContentSummary
{
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly string $type,
        public readonly int $authorId,
        public readonly string $title,
        public readonly string $slug,
        public readonly ?string $excerpt,
        public readonly ?int $featuredMediaId,
        public readonly string $status,
        public readonly ?DateTimeImmutable $publishedAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
