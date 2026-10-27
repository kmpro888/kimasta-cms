<?php

declare(strict_types=1);

namespace CMS\Modules\Media\Contracts;

use CMS\Modules\Media\Entity\MediaVariant;

/**
 * Reading the derived images of a media row.
 *
 * A companion to `MediaServiceInterface`, not a change to it: that interface is frozen, and the
 * editor only needs to *read* variants. Splitting the read out keeps the frozen upload pipeline
 * untouched while the theme can build a `srcset` from what the pipeline already produced.
 *
 * The alternative was a second implementation of the whole service, which would have been a
 * competing contract rather than a narrower one.
 */
interface MediaVariantQueryInterface
{
    /**
     * Every stored variant of one media row, ordered smallest first.
     *
     * @return list<MediaVariant>
     */
    public function variantsFor(int $mediaId, int $siteId): array;
}
