<?php

declare(strict_types=1);

namespace CMS\Modules\Migration\Repository;

use CMS\Core\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of migration bookkeeping.
 */
final class PdoMigrationRepository implements MigrationRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function targetId(int $siteId, string $sourceType, int $sourceId): ?int
    {
        $row = $this->connection->selectOne(
            'SELECT target_id FROM migration_maps '
            . 'WHERE site_id = :site_id AND source_type = :source_type AND source_id = :source_id LIMIT 1',
            ['site_id' => $siteId, 'source_type' => $sourceType, 'source_id' => $sourceId]
        );

        return $row === null ? null : (int) $row['target_id'];
    }

    public function recordMap(
        int $siteId,
        string $sourceType,
        int $sourceId,
        string $targetType,
        int $targetId,
    ): void {
        $this->connection->affect(
            'INSERT INTO migration_maps (site_id, source_type, source_id, target_type, target_id, created_at) '
            . 'VALUES (:site_id, :source_type, :source_id, :target_type, :target_id, :created_at) '
            // A rerun that reaches the same source row must not fail on the unique key; it
            // simply confirms the existing mapping.
            . 'ON DUPLICATE KEY UPDATE target_id = VALUES(target_id)',
            [
                'site_id' => $siteId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'created_at' => $this->now(),
            ]
        );
    }

    public function countMaps(int $siteId, string $sourceType): int
    {
        $row = $this->connection->selectOne(
            'SELECT COUNT(*) AS total FROM migration_maps WHERE site_id = :site_id AND source_type = :source_type',
            ['site_id' => $siteId, 'source_type' => $sourceType]
        );

        return (int) ($row['total'] ?? 0);
    }

    public function log(int $siteId, string $runId, string $level, array $entry): void
    {
        $this->connection->affect(
            'INSERT INTO migration_logs (site_id, run_id, level, source_type, source_id, field, reason, created_at) '
            . 'VALUES (:site_id, :run_id, :level, :source_type, :source_id, :field, :reason, :created_at)',
            [
                'site_id' => $siteId,
                'run_id' => $runId,
                'level' => $level,
                'source_type' => (string) $entry['source_type'],
                'source_id' => (int) $entry['source_id'],
                'field' => isset($entry['field']) ? (string) $entry['field'] : null,
                'reason' => mb_substr((string) $entry['reason'], 0, 500),
                'created_at' => $this->now(),
            ]
        );
    }

    public function logs(int $siteId, string $level, int $limit): array
    {
        return $this->connection->select(
            'SELECT run_id, level, source_type, source_id, field, reason, created_at '
            . 'FROM migration_logs WHERE site_id = :site_id AND level = :level '
            . 'ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)),
            ['site_id' => $siteId, 'level' => $level]
        );
    }

    public function logCounts(int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT level, COUNT(*) AS total FROM migration_logs WHERE site_id = :site_id GROUP BY level',
            ['site_id' => $siteId]
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['level']] = (int) $row['total'];
        }

        return $out;
    }

    public function mappedTargetIds(int $siteId, string $sourceType): array
    {
        $rows = $this->connection->select(
            'SELECT target_id FROM migration_maps '
            . 'WHERE site_id = :site_id AND source_type = :source_type ORDER BY target_id ASC',
            ['site_id' => $siteId, 'source_type' => $sourceType]
        );

        return array_map(static fn (array $row): int => (int) $row['target_id'], $rows);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
