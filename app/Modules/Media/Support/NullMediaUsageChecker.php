<?php

declare(strict_types=1);

namespace CMS\Modules\Media\Support;

use CMS\Modules\Media\Contracts\MediaUsageCheckerInterface;

/**
 * Default usage checker: assumes nothing references the media row.
 *
 * Bound only when the owning module has not registered a real checker, so Media
 * is usable on its own without pretending to know about content.
 */
final class NullMediaUsageChecker implements MediaUsageCheckerInterface
{
    public function countReferences(int $mediaId, int $siteId, ?string $path = null): int
    {
        return 0;
    }
}
