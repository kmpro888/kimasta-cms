<?php

declare(strict_types=1);

namespace CMS\Modules\Migration\Repository;

/**
 * `migration_maps` and `migration_logs`.
 */
interface MigrationRepositoryInterface
{
    /**
     * The target id for a source row, or null when it has not been imported.
     */
    public function targetId(int $siteId, string $sourceType, int $sourceId): ?int;

    public function recordMap(
        int $siteId,
        string $sourceType,
        int $sourceId,
        string $targetType,
        int $targetId,
    ): void;

    public function countMaps(int $siteId, string $sourceType): int;

    /**
     * @param array<string, mixed> $entry
     */
    public function log(int $siteId, string $runId, string $level, array $entry): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function logs(int $siteId, string $level, int $limit): array;

    /**
     * @return array<string, int> level => count
     */
    public function logCounts(int $siteId): array;

    /**
     * Target ids of a type that were created by the migration.
     *
     * Used by the URL integrity report to resolve new paths.
     *
     * @return list<int>
     */
    public function mappedTargetIds(int $siteId, string $sourceType): array;
}
