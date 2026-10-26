<?php

declare(strict_types=1);

namespace CMS\Modules\Revision\Service;

use CMS\Modules\Revision\Contracts\AutosaveServiceInterface;
use CMS\Modules\Revision\Repository\RevisionRepositoryInterface;
use CMS\Shared\Contracts\ClockInterface;

/**
 * Per-user draft autosave.
 *
 * Writes only `content_autosaves`. It never touches the content row and never
 * creates a revision, which is the whole point: an editor typing for ten minutes
 * must not burn the revision budget or change published content.
 */
final class AutosaveService implements AutosaveServiceInterface
{
    public function __construct(
        private readonly RevisionRepositoryInterface $revisions,
        private readonly ClockInterface $clock,
    ) {
    }

    public function save(int $contentId, int $userId, int $siteId, array|string $blocks): void
    {
        if ($contentId <= 0 || $userId <= 0 || $siteId <= 0) {
            throw new \InvalidArgumentException('Autosave requires a content id, user id and site id.');
        }

        $json = is_string($blocks)
            ? $blocks
            : (string) json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // A string payload must still be valid JSON, otherwise a typo would be
        // stored as a document nobody can decode later.
        if (json_decode($json, true) === null && $json !== 'null') {
            throw new \InvalidArgumentException('Autosave payload must be valid JSON.');
        }

        $this->revisions->saveAutosave(
            $contentId,
            $userId,
            $siteId,
            $json,
            $this->clock->now()->format('Y-m-d H:i:s')
        );
    }

    public function get(int $contentId, int $userId, int $siteId): ?array
    {
        $row = $this->revisions->findAutosave($contentId, $userId, $siteId);

        if ($row === null) {
            return null;
        }

        $decoded = json_decode((string) $row['content_json'], true);

        return is_array($decoded) ? $decoded : null;
    }

    public function savedAt(int $contentId, int $userId, int $siteId): ?\DateTimeImmutable
    {
        $row = $this->revisions->findAutosave($contentId, $userId, $siteId);

        if ($row === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable((string) $row['updated_at'], new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    public function clear(int $contentId, int $userId, int $siteId): void
    {
        $this->revisions->deleteAutosave($contentId, $userId, $siteId);
    }
}
