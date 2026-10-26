<?php

declare(strict_types=1);

namespace CMS\Modules\Taxonomy\Entity;

use DateTimeImmutable;

/**
 * Immutable term.
 *
 * `contentCount` is the precomputed counter stored on the row, so an archive page
 * can render "12 posts" without a COUNT(*).
 */
final class Term
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $siteId,
        public readonly int $taxonomyId,
        public readonly ?int $parentId,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly int $contentCount,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isRoot(): bool
    {
        return $this->parentId === null;
    }
}
