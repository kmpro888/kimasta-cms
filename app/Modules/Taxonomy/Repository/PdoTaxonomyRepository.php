<?php

declare(strict_types=1);

namespace CMS\Modules\Taxonomy\Repository;

use CMS\Core\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

/**
 * PDO implementation of taxonomy rows.
 */
final class PdoTaxonomyRepository implements TaxonomyRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findIdByName(int $siteId, string $name, ?string $contentType = null): ?int
    {
        $sql = 'SELECT id FROM taxonomies WHERE site_id = :site_id AND name = :name';
        $bindings = ['site_id' => $siteId, 'name' => $name];

        if ($contentType === null) {
            $sql .= ' AND content_type IS NULL';
        } else {
            $sql .= ' AND content_type = :content_type';
            $bindings['content_type'] = $contentType;
        }

        $value = $this->connection->scalar($sql . ' LIMIT 1', $bindings);

        return $value === null ? null : (int) $value;
    }

    public function ensure(int $siteId, string $name, ?string $contentType, bool $hierarchical): int
    {
        $existing = $this->findIdByName($siteId, $name, $contentType);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $this->connection->affect(
                'INSERT INTO taxonomies (site_id, name, content_type, hierarchical, created_at) '
                . 'VALUES (:site_id, :name, :content_type, :hierarchical, :created_at)',
                [
                    'site_id' => $siteId,
                    'name' => $name,
                    'content_type' => $contentType,
                    'hierarchical' => $hierarchical ? 1 : 0,
                    'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                ]
            );
        } catch (PDOException $exception) {
            // Two concurrent requests may race here; the unique key decides and
            // the loser simply reads the winner's row.
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
        }

        $id = $this->findIdByName($siteId, $name, $contentType);

        if ($id === null) {
            throw new \RuntimeException('Taxonomy could not be resolved after insert: ' . $name);
        }

        return $id;
    }

    public function listBySite(int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT id, name, hierarchical, content_type FROM taxonomies WHERE site_id = :site_id ORDER BY name ASC',
            ['site_id' => $siteId]
        );

        $mapped = [];

        foreach ($rows as $row) {
            $mapped[(string) $row['name']] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'hierarchical' => (bool) (int) $row['hierarchical'],
                'content_type' => isset($row['content_type']) ? (string) $row['content_type'] : null,
            ];
        }

        return $mapped;
    }

    public function findHierarchical(int $taxonomyId, int $siteId): bool
    {
        $value = $this->connection->scalar(
            'SELECT hierarchical FROM taxonomies WHERE id = :id AND site_id = :site_id LIMIT 1',
            ['id' => $taxonomyId, 'site_id' => $siteId]
        );

        return $value !== null && (int) $value === 1;
    }
}
