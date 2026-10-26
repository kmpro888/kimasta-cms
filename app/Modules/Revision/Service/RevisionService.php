<?php

declare(strict_types=1);

namespace CMS\Modules\Revision\Service;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Exceptions\ContentNotFoundException;
use CMS\Modules\Revision\Contracts\RevisionServiceInterface;
use CMS\Modules\Revision\Entity\Revision;
use CMS\Modules\Revision\Exceptions\ContentMissingException;
use CMS\Modules\Revision\Exceptions\RevisionNotFoundException;
use CMS\Modules\Revision\Repository\RevisionRepositoryInterface;
use CMS\Shared\Contracts\ClockInterface;

/**
 * Revision history with a hard cap.
 *
 * "Maximum 3 revisions per content row" is enforced after every insert, so the
 * invariant holds no matter how many times content is updated. Ten updates leave
 * three revisions, not ten.
 *
 * Revision reads and writes go through the Content contract, never through the
 * `contents` table: this module owns only `content_revisions` and
 * `content_autosaves`.
 */
final class RevisionService implements RevisionServiceInterface
{
    public function __construct(
        private readonly RevisionRepositoryInterface $revisions,
        private readonly ContentServiceInterface $contents,
        private readonly ClockInterface $clock,
    ) {
    }

    public function snapshot(int $contentId, int $siteId, int $createdBy): Revision
    {
        try {
            $content = $this->contents->get($contentId, $siteId);
        } catch (ContentNotFoundException) {
            throw new ContentMissingException();
        }

        $revision = $this->revisions->create($siteId, $contentId, [
            'created_by' => $createdBy,
            'title' => $content->title,
            'excerpt' => $content->excerpt,
            'content_json' => $content->contentJson,
            'content_html' => $content->contentHtml,
            'revision_number' => $this->revisions->nextRevisionNumber($contentId, $siteId),
        ]);

        // Enforce the cap immediately: the invariant must hold after every call,
        // not eventually.
        $this->revisions->pruneOldest($contentId, $siteId, self::MAX_PER_CONTENT);

        return $revision;
    }

    public function listFor(int $contentId, int $siteId): array
    {
        return $this->revisions->listFor($contentId, $siteId);
    }

    public function get(int $revisionId, int $siteId): Revision
    {
        $revision = $revisionId > 0 ? $this->revisions->findById($revisionId, $siteId) : null;

        if ($revision === null) {
            throw new RevisionNotFoundException();
        }

        return $revision;
    }

    public function restore(int $revisionId, int $siteId, int $restoredBy): int
    {
        $revision = $this->get($revisionId, $siteId);

        // Snapshot the state being replaced, so a restore is itself undoable.
        $this->snapshot($revision->contentId, $siteId, $restoredBy);

        $blocks = null;

        if ($revision->contentJson !== null && $revision->contentJson !== '') {
            $decoded = json_decode($revision->contentJson, true);
            $blocks = is_array($decoded) ? $decoded : null;
        }

        try {
            $this->contents->update($revision->contentId, $siteId, [
                'title' => $revision->title,
                'excerpt' => $revision->excerpt,
                'content_json' => $blocks,
            ]);
        } catch (ContentNotFoundException) {
            throw new ContentMissingException();
        }

        return $revision->contentId;
    }

    public function countFor(int $contentId, int $siteId): int
    {
        return $this->revisions->countFor($contentId, $siteId);
    }
}
