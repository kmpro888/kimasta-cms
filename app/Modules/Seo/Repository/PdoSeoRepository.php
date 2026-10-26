<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Repository;

use CMS\Core\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of `content_seo`.
 */
final class PdoSeoRepository implements SeoRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(int $contentId, int $siteId): ?array
    {
        return $this->connection->selectOne(
            'SELECT content_id, site_id, meta_title, meta_description, canonical_url, '
            . 'robots_index, robots_follow, og_title, og_description, og_image_id, schema_type, updated_at '
            . 'FROM content_seo WHERE content_id = :content_id AND site_id = :site_id LIMIT 1',
            ['content_id' => $contentId, 'site_id' => $siteId]
        );
    }

    public function put(int $contentId, int $siteId, array $attributes): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        // Single-statement upsert on the primary key.
        $this->connection->affect(
            'INSERT INTO content_seo (content_id, site_id, meta_title, meta_description, canonical_url, '
            . 'robots_index, robots_follow, og_title, og_description, og_image_id, schema_type, updated_at) '
            . 'VALUES (:content_id, :site_id, :meta_title, :meta_description, :canonical_url, '
            . ':robots_index, :robots_follow, :og_title, :og_description, :og_image_id, :schema_type, :updated_at) '
            . 'ON DUPLICATE KEY UPDATE meta_title = VALUES(meta_title), '
            . 'meta_description = VALUES(meta_description), canonical_url = VALUES(canonical_url), '
            . 'robots_index = VALUES(robots_index), robots_follow = VALUES(robots_follow), '
            . 'og_title = VALUES(og_title), og_description = VALUES(og_description), '
            . 'og_image_id = VALUES(og_image_id), schema_type = VALUES(schema_type), '
            . 'updated_at = VALUES(updated_at)',
            [
                'content_id' => $contentId,
                'site_id' => $siteId,
                'meta_title' => $attributes['meta_title'] ?? null,
                'meta_description' => $attributes['meta_description'] ?? null,
                'canonical_url' => $attributes['canonical_url'] ?? null,
                'robots_index' => ($attributes['robots_index'] ?? true) ? 1 : 0,
                'robots_follow' => ($attributes['robots_follow'] ?? true) ? 1 : 0,
                'og_title' => $attributes['og_title'] ?? null,
                'og_description' => $attributes['og_description'] ?? null,
                'og_image_id' => $attributes['og_image_id'] ?? null,
                'schema_type' => $attributes['schema_type'] ?? null,
                'updated_at' => $now,
            ]
        );
    }

    public function delete(int $contentId, int $siteId): void
    {
        $this->connection->affect(
            'DELETE FROM content_seo WHERE content_id = :content_id AND site_id = :site_id',
            ['content_id' => $contentId, 'site_id' => $siteId]
        );
    }
}
