<?php

declare(strict_types=1);

namespace CMS\Modules\Taxonomy\Repository;

use CMS\Core\Database\Connection;
use CMS\Modules\Taxonomy\Contracts\TermRepositoryInterface;
use CMS\Modules\Taxonomy\Entity\Term;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of term persistence and content association.
 *
 * Counter discipline: every attach/detach adjusts `terms.content_count` with the
 * same insert/delete that changes the association, and only when the association
 * row actually changed. That is what keeps the precomputed counter from drifting
 * under repeated attach calls.
 */
final class PdoTermRepository implements TermRepositoryInterface
{
    private const COLUMNS = 'id, site_id, taxonomy_id, parent_id, name, slug, description, '
        . 'content_count, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $termId, int $siteId): ?Term
    {
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM terms WHERE id = :id AND site_id = :site_id LIMIT 1',
            ['id' => $termId, 'site_id' => $siteId]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function findBySlug(string $slug, int $taxonomyId, int $siteId): ?Term
    {
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM terms '
            . 'WHERE site_id = :site_id AND taxonomy_id = :taxonomy_id AND slug = :slug LIMIT 1',
            ['site_id' => $siteId, 'taxonomy_id' => $taxonomyId, 'slug' => $slug]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function listByTaxonomy(int $taxonomyId, int $siteId, ?int $parentId = null): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM terms WHERE site_id = :site_id AND taxonomy_id = :taxonomy_id';
        $bindings = ['site_id' => $siteId, 'taxonomy_id' => $taxonomyId];

        if ($parentId === null) {
            // Root terms only when the caller asks for a hierarchy level.
            $sql .= ' AND parent_id IS NULL';
        } else {
            $sql .= ' AND parent_id = :parent_id';
            $bindings['parent_id'] = $parentId;
        }

        $rows = $this->connection->select($sql . ' ORDER BY name ASC', $bindings);

        return array_map(fn (array $row): Term => $this->hydrate($row), $rows);
    }

    /**
     * All terms of a taxonomy regardless of depth.
     *
     * @return list<Term>
     */
    public function listAllInTaxonomy(int $taxonomyId, int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . ' FROM terms '
            . 'WHERE site_id = :site_id AND taxonomy_id = :taxonomy_id ORDER BY parent_id IS NULL DESC, name ASC',
            ['site_id' => $siteId, 'taxonomy_id' => $taxonomyId]
        );

        return array_map(fn (array $row): Term => $this->hydrate($row), $rows);
    }

    public function findByIds(int $siteId, array $termIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $termIds)));

        if ($ids === []) {
            return [];
        }

        // Placeholders come from a validated integer list, never caller strings.
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $bindings = array_merge([$siteId], $ids);

        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . ' FROM terms WHERE site_id = ? AND id IN (' . $placeholders . ')',
            $bindings
        );

        $terms = [];

        foreach ($rows as $row) {
            $term = $this->hydrate($row);
            $terms[(int) $row['id']] = $term;
        }

        return $terms;
    }

    public function findBySlugAcrossTaxonomies(string $slug, int $siteId): ?Term
    {
        // A slug may legitimately exist in more than one taxonomy; the archive
        // route has no taxonomy in its URL, so the first match by taxonomy id wins
        // deterministically.
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM terms '
            . 'WHERE site_id = :site_id AND slug = :slug ORDER BY taxonomy_id ASC LIMIT 1',
            ['site_id' => $siteId, 'slug' => $slug]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function contentIdsForTerm(int $termId, int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT content_id FROM content_terms WHERE term_id = :term_id AND site_id = :site_id',
            ['term_id' => $termId, 'site_id' => $siteId]
        );

        return array_map(static fn (array $row): int => (int) $row['content_id'], $rows);
    }

    public function create(int $siteId, array $attributes): Term
    {
        $now = $this->now();

        $id = $this->connection->insert(
            'INSERT INTO terms (site_id, taxonomy_id, parent_id, name, slug, description, '
            . 'content_count, created_at, updated_at) '
            . 'VALUES (:site_id, :taxonomy_id, :parent_id, :name, :slug, :description, 0, :created_at, :updated_at)',
            [
                'site_id' => $siteId,
                'taxonomy_id' => (int) $attributes['taxonomy_id'],
                'parent_id' => $attributes['parent_id'] ?? null,
                'name' => (string) $attributes['name'],
                'slug' => (string) $attributes['slug'],
                'description' => $attributes['description'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $term = $this->findById($id, $siteId);

        if ($term === null) {
            throw new \RuntimeException('Term row disappeared immediately after insert.');
        }

        return $term;
    }

    public function update(int $termId, int $siteId, array $attributes): void
    {
        $sets = [];
        $bindings = ['id' => $termId, 'site_id' => $siteId];

        foreach (['name', 'slug', 'description', 'parent_id'] as $column) {
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
            'UPDATE terms SET ' . implode(', ', $sets) . ' WHERE id = :id AND site_id = :site_id',
            $bindings
        );
    }

    public function delete(int $termId, int $siteId): bool
    {
        // content_terms rows cascade; contents are never touched.
        return $this->connection->affect(
            'DELETE FROM terms WHERE id = :id AND site_id = :site_id',
            ['id' => $termId, 'site_id' => $siteId]
        ) > 0;
    }

    public function slugExists(string $slug, int $taxonomyId, int $siteId, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM terms WHERE site_id = :site_id AND taxonomy_id = :taxonomy_id AND slug = :slug';
        $bindings = ['site_id' => $siteId, 'taxonomy_id' => $taxonomyId, 'slug' => $slug];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $bindings['except_id'] = $exceptId;
        }

        return $this->connection->scalar($sql . ' LIMIT 1', $bindings) !== null;
    }

    public function attach(int $contentId, int $termId, int $siteId): bool
    {
        $now = $this->now();

        // The unique (content_id, term_id) primary key makes this idempotent.
        $inserted = $this->connection->affect(
            'INSERT IGNORE INTO content_terms (content_id, term_id, site_id, sort_order) '
            . 'VALUES (:content_id, :term_id, :site_id, 0)',
            ['content_id' => $contentId, 'term_id' => $termId, 'site_id' => $siteId]
        );

        if ($inserted === 0) {
            return false;
        }

        $this->adjustCount($termId, $siteId, 1);
        $this->touchTerm($termId, $siteId, $now);

        return true;
    }

    public function detach(int $contentId, int $termId, int $siteId): bool
    {
        $deleted = $this->connection->affect(
            'DELETE FROM content_terms WHERE content_id = :content_id AND term_id = :term_id AND site_id = :site_id',
            ['content_id' => $contentId, 'term_id' => $termId, 'site_id' => $siteId]
        );

        if ($deleted === 0) {
            return false;
        }

        $this->adjustCount($termId, $siteId, -1);

        return true;
    }

    public function termIdsForContent(int $contentId, int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT term_id FROM content_terms WHERE content_id = :content_id AND site_id = :site_id',
            ['content_id' => $contentId, 'site_id' => $siteId]
        );

        return array_map(static fn (array $row): int => (int) $row['term_id'], $rows);
    }

    public function termIdsForContents(int $siteId, array $contentIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $contentIds)));

        if ($ids === []) {
            return [];
        }

        // One query for the whole page: placeholders are generated from a
        // validated integer list, never from caller strings.
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $bindings = array_merge([$siteId], $ids);

        $rows = $this->connection->select(
            'SELECT content_id, term_id FROM content_terms '
            . 'WHERE site_id = ? AND content_id IN (' . $placeholders . ')',
            $bindings
        );

        $grouped = [];

        foreach ($ids as $id) {
            $grouped[$id] = [];
        }

        foreach ($rows as $row) {
            $grouped[(int) $row['content_id']][] = (int) $row['term_id'];
        }

        return $grouped;
    }

    public function adjustCount(int $termId, int $siteId, int $delta): void
    {
        // GREATEST guards against a transient negative if a delete races an
        // attach; the column is UNSIGNED and would otherwise wrap.
        $this->connection->affect(
            'UPDATE terms SET content_count = GREATEST(0, CAST(content_count AS SIGNED) + :delta) '
            . 'WHERE id = :id AND site_id = :site_id',
            ['delta' => $delta, 'id' => $termId, 'site_id' => $siteId]
        );
    }

    public function countByTaxonomy(int $taxonomyId, int $siteId): int
    {
        return (int) ($this->connection->scalar(
            'SELECT COUNT(*) FROM terms WHERE site_id = :site_id AND taxonomy_id = :taxonomy_id',
            ['site_id' => $siteId, 'taxonomy_id' => $taxonomyId]
        ) ?? 0);
    }

    private function touchTerm(int $termId, int $siteId, string $now): void
    {
        $this->connection->affect(
            'UPDATE terms SET updated_at = :updated_at WHERE id = :id AND site_id = :site_id',
            ['updated_at' => $now, 'id' => $termId, 'site_id' => $siteId]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Term
    {
        return new Term(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            taxonomyId: (int) $row['taxonomy_id'],
            parentId: isset($row['parent_id']) ? (int) $row['parent_id'] : null,
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            description: isset($row['description']) ? (string) $row['description'] : null,
            contentCount: (int) $row['content_count'],
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
