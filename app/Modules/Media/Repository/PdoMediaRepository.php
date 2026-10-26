<?php

declare(strict_types=1);

namespace CMS\Modules\Media\Repository;

use CMS\Core\Database\Connection;
use CMS\Modules\Media\Contracts\MediaRepositoryInterface;
use CMS\Modules\Media\Entity\Media;
use CMS\Modules\Media\Entity\MediaVariant;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of media persistence.
 *
 * Explicit column lists and an unconditional `site_id` predicate on every
 * statement, including DELETE, so a missing predicate can never become a
 * cross-tenant write.
 */
final class PdoMediaRepository implements MediaRepositoryInterface
{
    private const COLUMNS = 'id, site_id, uploaded_by, original_name, path, mime_type, extension, '
        . 'width, height, filesize, alt_text, caption, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id, int $siteId): ?Media
    {
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM media WHERE id = :id AND site_id = :site_id LIMIT 1',
            ['id' => $id, 'site_id' => $siteId]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function listBySite(int $siteId, int $limit = 20, int $offset = 0): array
    {
        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . ' FROM media WHERE site_id = :site_id '
            . 'ORDER BY created_at DESC, id DESC'
            . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            ['site_id' => $siteId]
        );

        return array_map(fn (array $row): Media => $this->hydrate($row), $rows);
    }

    public function countBySite(int $siteId): int
    {
        return (int) ($this->connection->scalar(
            'SELECT COUNT(*) FROM media WHERE site_id = :site_id',
            ['site_id' => $siteId]
        ) ?? 0);
    }

    public function create(int $siteId, array $attributes): Media
    {
        $now = $this->now();

        $id = $this->connection->insert(
            'INSERT INTO media (site_id, uploaded_by, original_name, path, mime_type, extension, '
            . 'width, height, filesize, alt_text, caption, created_at, updated_at) '
            . 'VALUES (:site_id, :uploaded_by, :original_name, :path, :mime_type, :extension, '
            . ':width, :height, :filesize, :alt_text, :caption, :created_at, :updated_at)',
            [
                'site_id' => $siteId,
                'uploaded_by' => (int) $attributes['uploaded_by'],
                'original_name' => $attributes['original_name'] ?? null,
                'path' => (string) $attributes['path'],
                'mime_type' => (string) $attributes['mime_type'],
                'extension' => (string) $attributes['extension'],
                'width' => $attributes['width'] ?? null,
                'height' => $attributes['height'] ?? null,
                'filesize' => (int) ($attributes['filesize'] ?? 0),
                'alt_text' => $attributes['alt_text'] ?? null,
                'caption' => $attributes['caption'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $media = $this->findById($id, $siteId);

        if ($media === null) {
            throw new \RuntimeException('Media row disappeared immediately after insert.');
        }

        return $media;
    }

    public function update(int $id, int $siteId, array $attributes): void
    {
        $sets = [];
        $bindings = ['id' => $id, 'site_id' => $siteId];

        foreach (['alt_text', 'caption', 'original_name'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $sets[] = $column . ' = :' . $column;
                $bindings[$column] = $attributes[$column];
            }
        }

        if ($sets === []) {
            return;
        }

        $sets[] = 'updated_at = :updated_at';
        $bindings['updated_at'] = $this->now();

        $this->connection->affect(
            'UPDATE media SET ' . implode(', ', $sets) . ' WHERE id = :id AND site_id = :site_id',
            $bindings
        );
    }

    public function delete(int $id, int $siteId): bool
    {
        return $this->connection->affect(
            'DELETE FROM media WHERE id = :id AND site_id = :site_id',
            ['id' => $id, 'site_id' => $siteId]
        ) > 0;
    }

    public function variantsFor(int $mediaId, int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT id, media_id, site_id, variant, format, path, width, height, filesize '
            . 'FROM media_variants WHERE media_id = :media_id AND site_id = :site_id '
            . 'ORDER BY variant ASC, format ASC',
            ['media_id' => $mediaId, 'site_id' => $siteId]
        );

        return array_map(fn (array $row): MediaVariant => new MediaVariant(
            id: (int) $row['id'],
            mediaId: (int) $row['media_id'],
            siteId: (int) $row['site_id'],
            variant: (string) $row['variant'],
            format: (string) $row['format'],
            path: (string) $row['path'],
            width: isset($row['width']) ? (int) $row['width'] : null,
            height: isset($row['height']) ? (int) $row['height'] : null,
            filesize: isset($row['filesize']) ? (int) $row['filesize'] : null,
        ), $rows);
    }

    public function addVariant(
        int $mediaId,
        int $siteId,
        string $variant,
        string $format,
        string $path,
        ?int $width,
        ?int $height,
        ?int $filesize
    ): void {
        // Upsert on (media_id, variant, format) so re-running the pipeline
        // refreshes a row instead of duplicating it.
        $this->connection->affect(
            'INSERT INTO media_variants (media_id, site_id, variant, format, path, width, height, filesize) '
            . 'VALUES (:media_id, :site_id, :variant, :format, :path, :width, :height, :filesize) '
            . 'ON DUPLICATE KEY UPDATE path = VALUES(path), width = VALUES(width), '
            . 'height = VALUES(height), filesize = VALUES(filesize)',
            [
                'media_id' => $mediaId,
                'site_id' => $siteId,
                'variant' => $variant,
                'format' => $format,
                'path' => $path,
                'width' => $width,
                'height' => $height,
                'filesize' => $filesize,
            ]
        );
    }

    public function deleteVariants(int $mediaId, int $siteId): void
    {
        $this->connection->affect(
            'DELETE FROM media_variants WHERE media_id = :media_id AND site_id = :site_id',
            ['media_id' => $mediaId, 'site_id' => $siteId]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Media
    {
        return new Media(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            uploadedBy: (int) $row['uploaded_by'],
            originalName: isset($row['original_name']) ? (string) $row['original_name'] : null,
            path: (string) $row['path'],
            mimeType: (string) $row['mime_type'],
            extension: (string) $row['extension'],
            width: isset($row['width']) ? (int) $row['width'] : null,
            height: isset($row['height']) ? (int) $row['height'] : null,
            filesize: (int) $row['filesize'],
            altText: isset($row['alt_text']) ? (string) $row['alt_text'] : null,
            caption: isset($row['caption']) ? (string) $row['caption'] : null,
            createdAt: $this->toDate($row['created_at']) ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
            updatedAt: $this->toDate($row['updated_at']) ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
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
