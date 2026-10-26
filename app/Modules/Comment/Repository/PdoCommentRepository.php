<?php

declare(strict_types=1);

namespace CMS\Modules\Comment\Repository;

use CMS\Core\Database\Connection;
use CMS\Modules\Comment\Entity\Comment;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of `comments`.
 */
final class PdoCommentRepository implements CommentRepositoryInterface
{
    private const COLUMNS = 'id, site_id, content_id, parent_id, user_id, author_name, '
        . 'body, status, depth, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(int $siteId, array $attributes): Comment
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->connection->affect(
            'INSERT INTO comments (site_id, content_id, parent_id, user_id, author_name, body, status, depth, '
            . 'created_at, updated_at) '
            . 'VALUES (:site_id, :content_id, :parent_id, :user_id, :author_name, :body, :status, :depth, '
            . ':created_at, :updated_at)',
            [
                'site_id' => $siteId,
                'content_id' => (int) $attributes['content_id'],
                'parent_id' => $attributes['parent_id'] ?? null,
                'user_id' => (int) $attributes['user_id'],
                'author_name' => (string) $attributes['author_name'],
                'body' => (string) $attributes['body'],
                'status' => (string) ($attributes['status'] ?? Comment::STATUS_PENDING),
                'depth' => (int) ($attributes['depth'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $id = (int) $this->connection->lastInsertId();

        $created = $this->find($id, $siteId);

        if ($created === null) {
            throw new \RuntimeException('Comment was inserted but could not be read back.');
        }

        return $created;
    }

    public function find(int $id, int $siteId): ?Comment
    {
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM comments WHERE id = :id AND site_id = :site_id LIMIT 1',
            ['id' => $id, 'site_id' => $siteId]
        );

        return $row === null ? null : Comment::fromRow($row);
    }

    public function approvedForContent(int $siteId, int $contentId, int $limit = 200): array
    {
        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . ' FROM comments '
            . 'WHERE site_id = :site_id AND content_id = :content_id AND status = :status '
            . 'ORDER BY created_at ASC, id ASC LIMIT ' . max(1, $limit),
            ['site_id' => $siteId, 'content_id' => $contentId, 'status' => Comment::STATUS_APPROVED]
        );

        return array_map(static fn (array $row): Comment => Comment::fromRow($row), $rows);
    }

    public function allForContent(int $siteId, int $contentId, ?string $status = null, int $limit = 200): array
    {
        $clauses = ['site_id = :site_id', 'content_id = :content_id'];
        $bindings = ['site_id' => $siteId, 'content_id' => $contentId];

        if ($status !== null && $status !== '') {
            $clauses[] = 'status = :status';
            $bindings['status'] = $status;
        }

        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . ' FROM comments WHERE ' . implode(' AND ', $clauses)
            . ' ORDER BY created_at ASC, id ASC LIMIT ' . max(1, $limit),
            $bindings
        );

        return array_map(static fn (array $row): Comment => Comment::fromRow($row), $rows);
    }

    public function listForSite(int $siteId, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $clauses = ['site_id = :site_id'];
        $bindings = ['site_id' => $siteId];

        if ($status !== null && $status !== '') {
            $clauses[] = 'status = :status';
            $bindings['status'] = $status;
        }

        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . ' FROM comments WHERE ' . implode(' AND ', $clauses)
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $bindings
        );

        return array_map(static fn (array $row): Comment => Comment::fromRow($row), $rows);
    }

    public function countForSite(int $siteId, ?string $status = null): int
    {
        $clauses = ['site_id = :site_id'];
        $bindings = ['site_id' => $siteId];

        if ($status !== null && $status !== '') {
            $clauses[] = 'status = :status';
            $bindings['status'] = $status;
        }

        $row = $this->connection->selectOne(
            'SELECT COUNT(*) AS total FROM comments WHERE ' . implode(' AND ', $clauses),
            $bindings
        );

        return (int) ($row['total'] ?? 0);
    }

    public function countApprovedForContent(int $siteId, int $contentId): int
    {
        $row = $this->connection->selectOne(
            'SELECT COUNT(*) AS total FROM comments '
            . 'WHERE site_id = :site_id AND content_id = :content_id AND status = :status',
            ['site_id' => $siteId, 'content_id' => $contentId, 'status' => Comment::STATUS_APPROVED]
        );

        return (int) ($row['total'] ?? 0);
    }

    public function updateStatus(int $id, int $siteId, string $status): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->connection->affect(
            'UPDATE comments SET status = :status, updated_at = :updated_at '
            . 'WHERE id = :id AND site_id = :site_id',
            ['status' => $status, 'updated_at' => $now, 'id' => $id, 'site_id' => $siteId]
        );
    }

    public function delete(int $id, int $siteId): bool
    {
        // A foreign key cascade removes replies, so a deleted parent cannot leave
        // orphans pointing at a missing row.
        return $this->connection->affect(
            'DELETE FROM comments WHERE id = :id AND site_id = :site_id',
            ['id' => $id, 'site_id' => $siteId]
        ) > 0;
    }
}
