<?php

declare(strict_types=1);

namespace CMS\Modules\Media\Contracts;

/**
 * Answers "is this media still referenced?" without Media touching a foreign
 * table.
 *
 * Media owns `media`/`media_variants`; content owns `contents` and its
 * `featured_media_id`. Rather than reach across that boundary with SQL, Media
 * declares the question and whichever module owns the referencing table answers
 * it. The default binding answers zero, so Media works standalone and simply
 * cannot be over-protected by accident.
 */
interface MediaUsageCheckerInterface
{
    /**
     * Number of entities on this site that still point at the media row.
     *
     * `$path` is passed because a reference is not always an id. A featured image points at
     * the row, but an image inside an article's body points at the stored file by URL, which
     * is what the page actually renders. Without the path, deleting a file that an article
     * displays would look safe and leave a broken image on a published page.
     *
     * Optional so an implementation that only knows about ids stays valid.
     */
    public function countReferences(int $mediaId, int $siteId, ?string $path = null): int;
}
