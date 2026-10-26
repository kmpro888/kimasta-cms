<?php

declare(strict_types=1);

namespace CMS\Modules\Revision\Entity;

use DateTimeImmutable;

/**
 * One immutable snapshot of a content row.
 *
 * Only the fields worth restoring are kept: title, excerpt and the block payload,
 * plus the compiled HTML so a restored page renders without recompiling.
 */
final class Revision
{
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly int $contentId,
        public readonly int $createdBy,
        public readonly string $title,
        public readonly ?string $excerpt,
        public readonly ?string $contentJson,
        public readonly ?string $contentHtml,
        public readonly int $revisionNumber,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
