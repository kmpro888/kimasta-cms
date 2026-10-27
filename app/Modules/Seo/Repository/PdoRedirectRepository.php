<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Repository;

use CMS\Core\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of `redirects`.
 */
final class PdoRedirectRepository implements RedirectRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(int $siteId, string $sourcePath): ?array
    {
        $row = $this->connection->selectOne(
            'SELECT target_url, status_code FROM redirects '
            . 'WHERE site_id = :site_id AND source_path = :source_path LIMIT 1',
            ['site_id' => $siteId, 'source_path' => $sourcePath]
        );

        if ($row === null) {
            return null;
        }

        return [
            'target_url' => (string) $row['target_url'],
            'status_code' => (int) $row['status_code'],
        ];
    }

    public function put(int $siteId, string $sourcePath, string $targetUrl, int $statusCode): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->connection->affect(
            'INSERT INTO redirects (site_id, source_path, target_url, status_code, hit_count, created_at, updated_at) '
            . 'VALUES (:site_id, :source_path, :target_url, :status_code, 0, :created_at, :updated_at) '
            . 'ON DUPLICATE KEY UPDATE target_url = VALUES(target_url), '
            . 'status_code = VALUES(status_code), updated_at = VALUES(updated_at)',
            [
                'site_id' => $siteId,
                'source_path' => $sourcePath,
                'target_url' => $targetUrl,
                'status_code' => $statusCode,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function delete(int $siteId, string $sourcePath): bool
    {
        return $this->connection->affect(
            'DELETE FROM redirects WHERE site_id = :site_id AND source_path = :source_path',
            ['site_id' => $siteId, 'source_path' => $sourcePath]
        ) > 0;
    }

    /**
     * Fold a batch of counted hits into the row.
     *
     * Called by cron, never from a page view: one statement per redirect per flush instead of one
     * per visitor.
     */
    public function incrementHits(int $siteId, string $sourcePath, int $by): void
    {
        if ($by <= 0) {
            return;
        }

        $this->connection->statement(
            'UPDATE redirects SET hit_count = hit_count + :hits, updated_at = :updated_at '
            . 'WHERE site_id = :site_id AND source_path = :source_path',
            [
                'hits' => $by,
                'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'site_id' => $siteId,
                'source_path' => $sourcePath,
            ]
        );
    }
    public function listForSite(int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT source_path, target_url, status_code, hit_count FROM redirects '
            . 'WHERE site_id = :site_id ORDER BY source_path ASC',
            ['site_id' => $siteId]
        );

        return array_map(static fn (array $row): array => [
            'source_path' => (string) $row['source_path'],
            'target_url' => (string) $row['target_url'],
            'status_code' => (int) $row['status_code'],
            'hit_count' => (int) $row['hit_count'],
        ], $rows);
    }
}
