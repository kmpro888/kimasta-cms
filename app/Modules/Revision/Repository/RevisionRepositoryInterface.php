<?php

declare(strict_types=1);

namespace CMS\Modules\Revision\Repository;

use CMS\Modules\Revision\Entity\Revision;

/**
 * Revision and autosave persistence.
 *
 * The repository owns only `content_revisions` and `content_autosaves`. It never
 * reads or writes `contents`: the calling service supplies the snapshot fields and
 * applies restores through the Content contract.
 */
interface RevisionRepositoryInterface
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function create(int $siteId, int $contentId, array $snapshot): Revision;

    /**
     * @return list<Revision>
     */
    public function listFor(int $contentId, int $siteId): array;

    public function findById(int $revisionId, int $siteId): ?Revision;

    public function countFor(int $contentId, int $siteId): int;

    /**
     * Delete the oldest revisions beyond the allowed keep-count.
     *
     * @return int number removed
     */
    public function pruneOldest(int $contentId, int $siteId, int $keep): int;

    public function nextRevisionNumber(int $contentId, int $siteId): int;

    public function saveAutosave(int $contentId, int $userId, int $siteId, string $contentJson, string $updatedAt): void;

    public function findAutosave(int $contentId, int $userId, int $siteId): ?array;

    public function deleteAutosave(int $contentId, int $userId, int $siteId): void;
}
