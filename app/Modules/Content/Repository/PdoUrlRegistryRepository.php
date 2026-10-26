<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Repository;

use CMS\Core\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of the URL registry.
 */
final class PdoUrlRegistryRepository implements UrlRegistryRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function put(int $siteId, string $path, string $entityType, int $entityId): void
    {
        // Upsert on the primary key (site_id, path): re-registering the same
        // path for the same entity is a no-op refresh of updated_at and
        // canonical, not an error.
        $this->connection->affect(
            'INSERT INTO url_registry (site_id, path, entity_type, entity_id, canonical, updated_at) '
            . 'VALUES (:site_id, :path, :entity_type, :entity_id, 1, :updated_at) '
            . 'ON DUPLICATE KEY UPDATE entity_type = VALUES(entity_type), '
            . 'entity_id = VALUES(entity_id), canonical = 1, updated_at = VALUES(updated_at)',
            [
                'site_id' => $siteId,
                'path' => $path,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'updated_at' => $this->now(),
            ]
        );
    }

    public function forget(int $siteId, string $entityType, int $entityId): void
    {
        $this->connection->affect(
            'DELETE FROM url_registry WHERE site_id = :site_id AND entity_type = :entity_type AND entity_id = :entity_id',
            ['site_id' => $siteId, 'entity_type' => $entityType, 'entity_id' => $entityId]
        );
    }

    public function resolve(int $siteId, string $path): ?array
    {
        $row = $this->connection->selectOne(
            'SELECT entity_type, entity_id FROM url_registry '
            . 'WHERE site_id = :site_id AND path = :path LIMIT 1',
            ['site_id' => $siteId, 'path' => $path]
        );

        if ($row === null) {
            return null;
        }

        return [
            'entity_type' => (string) $row['entity_type'],
            'entity_id' => (int) $row['entity_id'],
        ];
    }

    public function isTakenByAnother(int $siteId, string $path, string $entityType, int $entityId): bool
    {
        $row = $this->connection->selectOne(
            'SELECT entity_type, entity_id FROM url_registry '
            . 'WHERE site_id = :site_id AND path = :path LIMIT 1',
            ['site_id' => $siteId, 'path' => $path]
        );

        if ($row === null) {
            return false;
        }

        return (string) $row['entity_type'] !== $entityType
            || (int) $row['entity_id'] !== $entityId;
    }

    public function pathFor(int $siteId, string $entityType, int $entityId): ?string
    {
        $value = $this->connection->scalar(
            'SELECT path FROM url_registry WHERE site_id = :site_id AND entity_type = :entity_type '
            . 'AND entity_id = :entity_id AND canonical = 1 LIMIT 1',
            ['site_id' => $siteId, 'entity_type' => $entityType, 'entity_id' => $entityId]
        );

        return $value === null ? null : (string) $value;
    }

    public function allForSite(int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT path, entity_type, entity_id FROM url_registry WHERE site_id = :site_id',
            ['site_id' => $siteId]
        );

        $mapped = [];

        foreach ($rows as $row) {
            $mapped[(string) $row['path']] = [
                'entity_type' => (string) $row['entity_type'],
                'entity_id' => (int) $row['entity_id'],
            ];
        }

        return $mapped;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
