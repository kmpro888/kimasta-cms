<?php

declare(strict_types=1);

namespace CMS\Modules\Revision\Repository;

use CMS\Core\Database\Connection;
use CMS\Modules\Revision\Entity\Revision;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of revision and autosave persistence.
 *
 * Pruning is done in the database with `DELETE … WHERE id NOT IN (newest N)`
 * rather than by loading rows and deleting in PHP, so the cap holds even if the
 * table already contains more than the limit.
 */
final class PdoRevisionRepository implements RevisionRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(int $siteId, int $contentId, array $snapshot): Revision
    {
        $now = $this->now();

        $id = $this->connection->insert(
            'INSERT INTO content_revisions (site_id, content_id, created_by, title, excerpt, '
            . 'content_json, content_html, revision_number, created_at) '
            . 'VALUES (:site_id, :content_id, :created_by, :title, :excerpt, '
            . ':content_json, :content_html, :revision_number, :created_at)',
            [
                'site_id' => $siteId,
                'content_id' => $contentId,
                'created_by' => (int) $snapshot['created_by'],
                'title' => (string) $snapshot['title'],
                'excerpt' => $snapshot['excerpt'] ?? null,
                'content_json' => $snapshot['content_json'] ?? null,
                'content_html' => $snapshot['content_html'] ?? null,
                'revision_number' => (int) $snapshot['revision_number'],
                'created_at' => $now,
            ]
        );

        $revision = $this->findById($id, $siteId);

        if ($revision === null) {
            throw new \RuntimeException('Revision row disappeared immediately after insert.');
        }

        return $revision;
    }

    public function listFor(int $contentId, int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT id, site_id, content_id, created_by, title, excerpt, content_json, '
            . 'content_html, revision_number, created_at FROM content_revisions '
            . 'WHERE site_id = :site_id AND content_id = :content_id '
            . 'ORDER BY revision_number DESC, id DESC',
            ['site_id' => $siteId, 'content_id' => $contentId]
        );

        return array_map(fn (array $row): Revision => $this->hydrate($row), $rows);
    }

    public function findById(int $revisionId, int $siteId): ?Revision
    {
        $row = $this->connection->selectOne(
            'SELECT id, site_id, content_id, created_by, title, excerpt, content_json, '
            . 'content_html, revision_number, created_at FROM content_revisions '
            . 'WHERE id = :id AND site_id = :site_id LIMIT 1',
            ['id' => $revisionId, 'site_id' => $siteId]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function countFor(int $contentId, int $siteId): int
    {
        return (int) ($this->connection->scalar(
            'SELECT COUNT(*) FROM content_revisions WHERE site_id = :site_id AND content_id = :content_id',
            ['site_id' => $siteId, 'content_id' => $contentId]
        ) ?? 0);
    }

    public function pruneOldest(int $contentId, int $siteId, int $keep): int
    {
        $keep = max(0, $keep);

        // Identify the rows to retain, then delete everything else in one
        // statement. Ordering matches listFor() so "oldest" is unambiguous.
        $keepIds = $this->connection->select(
            'SELECT id FROM content_revisions WHERE site_id = :site_id AND content_id = :content_id '
            . 'ORDER BY revision_number DESC, id DESC LIMIT ' . $keep,
            ['site_id' => $siteId, 'content_id' => $contentId]
        );

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $keepIds);

        if ($ids === []) {
            return $this->connection->affect(
                'DELETE FROM content_revisions WHERE site_id = :site_id AND content_id = :content_id',
                ['site_id' => $siteId, 'content_id' => $contentId]
            );
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        return $this->connection->affect(
            'DELETE FROM content_revisions WHERE site_id = ? AND content_id = ? AND id NOT IN (' . $placeholders . ')',
            array_merge([$siteId, $contentId], $ids)
        );
    }

    public function nextRevisionNumber(int $contentId, int $siteId): int
    {
        $max = $this->connection->scalar(
            'SELECT MAX(revision_number) FROM content_revisions WHERE site_id = :site_id AND content_id = :content_id',
            ['site_id' => $siteId, 'content_id' => $contentId]
        );

        return $max === null ? 1 : ((int) $max) + 1;
    }

    public function saveAutosave(int $contentId, int $userId, int $siteId, string $contentJson, string $updatedAt): void
    {
        // Primary key (content_id, user_id) makes this a single-row replace, so a
        // user never accumulates multiple drafts for one post.
        $this->connection->affect(
            'INSERT INTO content_autosaves (content_id, user_id, site_id, content_json, updated_at) '
            . 'VALUES (:content_id, :user_id, :site_id, :content_json, :updated_at) '
            . 'ON DUPLICATE KEY UPDATE content_json = VALUES(content_json), '
            . 'site_id = VALUES(site_id), updated_at = VALUES(updated_at)',
            [
                'content_id' => $contentId,
                'user_id' => $userId,
                'site_id' => $siteId,
                'content_json' => $contentJson,
                'updated_at' => $updatedAt,
            ]
        );
    }

    public function findAutosave(int $contentId, int $userId, int $siteId): ?array
    {
        $row = $this->connection->selectOne(
            'SELECT content_json, updated_at FROM content_autosaves '
            . 'WHERE content_id = :content_id AND user_id = :user_id AND site_id = :site_id LIMIT 1',
            ['content_id' => $contentId, 'user_id' => $userId, 'site_id' => $siteId]
        );

        return $row === null ? null : $row;
    }

    public function deleteAutosave(int $contentId, int $userId, int $siteId): void
    {
        $this->connection->affect(
            'DELETE FROM content_autosaves WHERE content_id = :content_id AND user_id = :user_id AND site_id = :site_id',
            ['content_id' => $contentId, 'user_id' => $userId, 'site_id' => $siteId]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Revision
    {
        return new Revision(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            contentId: (int) $row['content_id'],
            createdBy: (int) $row['created_by'],
            title: (string) $row['title'],
            excerpt: isset($row['excerpt']) ? (string) $row['excerpt'] : null,
            contentJson: isset($row['content_json']) ? (string) $row['content_json'] : null,
            contentHtml: isset($row['content_html']) ? (string) $row['content_html'] : null,
            revisionNumber: (int) $row['revision_number'],
            createdAt: $this->toDate($row['created_at']) ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    private function toDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
