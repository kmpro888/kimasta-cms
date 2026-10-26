<?php

declare(strict_types=1);

namespace CMS\Modules\Editorial\Support;

use CMS\Modules\Editorial\Contracts\ScheduledContentFinderInterface;

/**
 * Default finder: nothing is ever due.
 *
 * Bound only when the Content module has not registered the real finder, so
 * Editorial works standalone without pretending to know about `contents`.
 */
final class NullScheduledContentFinder implements ScheduledContentFinderInterface
{
    public function due(?\DateTimeImmutable $now = null, ?int $siteId = null, int $limit = 50): array
    {
        return [];
    }
}
