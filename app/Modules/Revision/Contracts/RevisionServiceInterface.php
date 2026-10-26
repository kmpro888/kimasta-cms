<?php

declare(strict_types=1);

namespace CMS\Modules\Revision\Contracts;

use CMS\Modules\Revision\Entity\Revision;

/**
 * Content revision history.
 *
 * The cap is a product decision, not an implementation detail: history must be
 * useful without becoming WordPress-style unbounded growth. The service keeps at
 * most `MAX_PER_CONTENT` revisions and drops the oldest when a new one would
 * exceed it.
 *
 * FROZEN CONTRACT.
 */
interface RevisionServiceInterface
{
    /**
     * How many revisions are retained per content row.
     */
    public const MAX_PER_CONTENT = 3;

    /**
     * Snapshot the *current* state of a content row before it is changed.
     *
     * Called by the update path, not by the author. Returns the stored revision.
     *
     * @throws \CMS\Modules\Revision\Exceptions\ContentMissingException
     */
    public function snapshot(int $contentId, int $siteId, int $createdBy): Revision;

    /**
     * Revisions for a content row, newest first.
     *
     * @return list<Revision>
     */
    public function listFor(int $contentId, int $siteId): array;

    /**
     * @throws \CMS\Modules\Revision\Exceptions\RevisionNotFoundException
     */
    public function get(int $revisionId, int $siteId): Revision;

    /**
     * Copy a revision's title/excerpt/blocks back onto the content row.
     *
     * Takes a snapshot of the pre-restore state first, so a restore is itself
     * undoable.
     *
     * @throws \CMS\Modules\Revision\Exceptions\RevisionNotFoundException
     * @throws \CMS\Modules\Revision\Exceptions\ContentMissingException
     */
    public function restore(int $revisionId, int $siteId, int $restoredBy): int;

    /**
     * Number of revisions retained for a content row.
     */
    public function countFor(int $contentId, int $siteId): int;
}
