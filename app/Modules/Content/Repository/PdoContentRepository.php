<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Repository;

use CMS\Core\Database\Connection;
use CMS\Modules\Content\Contracts\ContentRepositoryInterface;
use CMS\Modules\Content\Entity\Content;
use CMS\Modules\Content\Entity\ContentSummary;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of content persistence.
 *
 * Two things are deliberate and load-bearing:
 *
 * 1. Listing queries use a separate, narrow column list. A homepage renders
 *    dozens of cards and needs no block payload, so `content_json` is simply not
 *    selectable from `paginate()` — the heavy column cannot leak into a listing
 *    by accident.
 * 2. Every statement carries `site_id`, including UPDATE and DELETE, so a
 *    forgotten predicate cannot become a cross-tenant write.
 */
final class PdoContentRepository implements ContentRepositoryInterface
{
    private const COLUMNS = 'id, site_id, type, author_id, title, slug, excerpt, '
        . 'content_json, content_html, content_plain, status, featured_media_id, '
        . 'comment_status, published_at, scheduled_at, created_at, updated_at';

    private const SUMMARY_COLUMNS = 'id, site_id, type, author_id, title, slug, excerpt, '
        . 'featured_media_id, status, published_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id, int $siteId): ?Content
    {
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM contents WHERE id = :id AND site_id = :site_id LIMIT 1',
            ['id' => $id, 'site_id' => $siteId]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function findBySlug(string $slug, string $type, int $siteId): ?Content
    {
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM contents '
            . 'WHERE site_id = :site_id AND type = :type AND slug = :slug LIMIT 1',
            ['site_id' => $siteId, 'type' => $type, 'slug' => $slug]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function create(int $siteId, array $attributes): Content
    {
        $now = $this->now();

        $id = $this->connection->insert(
            'INSERT INTO contents (site_id, type, author_id, title, slug, excerpt, content_json, '
            . 'content_html, content_plain, status, featured_media_id, comment_status, '
            . 'published_at, scheduled_at, created_at, updated_at) '
            . 'VALUES (:site_id, :type, :author_id, :title, :slug, :excerpt, :content_json, '
            . ':content_html, :content_plain, :status, :featured_media_id, :comment_status, '
            . ':published_at, :scheduled_at, :created_at, :updated_at)',
            [
                'site_id' => $siteId,
                'type' => (string) $attributes['type'],
                'author_id' => (int) $attributes['author_id'],
                'title' => (string) $attributes['title'],
                'slug' => (string) $attributes['slug'],
                'excerpt' => $attributes['excerpt'] ?? null,
                'content_json' => $attributes['content_json'] ?? null,
                'content_html' => $attributes['content_html'] ?? null,
                'content_plain' => $attributes['content_plain'] ?? null,
                'status' => (string) ($attributes['status'] ?? Content::STATUS_DRAFT),
                'featured_media_id' => $attributes['featured_media_id'] ?? null,
                'comment_status' => (string) ($attributes['comment_status'] ?? 'open'),
                'published_at' => $attributes['published_at'] ?? null,
                'scheduled_at' => $attributes['scheduled_at'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $content = $this->findById($id, $siteId);

        if ($content === null) {
            throw new \RuntimeException('Content row disappeared immediately after insert.');
        }

        return $content;
    }

    public function update(int $id, int $siteId, array $attributes): void
    {
        $sets = [];
        $bindings = ['id' => $id, 'site_id' => $siteId];

        $mappable = [
            'title', 'slug', 'type', 'excerpt', 'content_json', 'content_html',
            'content_plain', 'status', 'featured_media_id', 'comment_status',
            'published_at', 'scheduled_at', 'author_id',
        ];

        foreach ($mappable as $column) {
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
            'UPDATE contents SET ' . implode(', ', $sets) . ' WHERE id = :id AND site_id = :site_id',
            $bindings
        );
    }

    public function trash(int $id, int $siteId, DateTimeImmutable $at): bool
    {
        return $this->connection->affect(
            'UPDATE contents SET status = :status, updated_at = :updated_at '
            . 'WHERE id = :id AND site_id = :site_id',
            [
                'status' => Content::STATUS_TRASH,
                'updated_at' => $this->format($at),
                'id' => $id,
                'site_id' => $siteId,
            ]
        ) > 0;
    }

    public function purge(int $id, int $siteId): bool
    {
        return $this->connection->affect(
            'DELETE FROM contents WHERE id = :id AND site_id = :site_id',
            ['id' => $id, 'site_id' => $siteId]
        ) > 0;
    }

    public function slugExists(string $slug, string $type, int $siteId, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM contents WHERE site_id = :site_id AND type = :type AND slug = :slug';
        $bindings = ['site_id' => $siteId, 'type' => $type, 'slug' => $slug];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $bindings['except_id'] = $exceptId;
        }

        return $this->connection->scalar($sql . ' LIMIT 1', $bindings) !== null;
    }

    public function paginate(int $siteId, array $criteria = [], int $limit = 20, int $offset = 0): array
    {
        [$where, $bindings] = $this->buildWhere($siteId, $criteria);

        $order = $this->orderClause($criteria);

        $rows = $this->connection->select(
            'SELECT ' . self::SUMMARY_COLUMNS . ' FROM contents ' . $where . $order
            . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $bindings
        );

        return array_map(fn (array $row): ContentSummary => $this->hydrateSummary($row), $rows);
    }

    public function count(int $siteId, array $criteria = []): int
    {
        [$where, $bindings] = $this->buildWhere($siteId, $criteria);

        $value = $this->connection->scalar(
            'SELECT COUNT(*) FROM contents ' . $where,
            $bindings
        );

        return (int) ($value ?? 0);
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(int $siteId, array $criteria): array
    {
        $clauses = ['site_id = :site_id'];
        $bindings = ['site_id' => $siteId];

        if (isset($criteria['type']) && $criteria['type'] !== '') {
            $clauses[] = 'type = :type';
            $bindings['type'] = (string) $criteria['type'];
        }

        if (isset($criteria['status']) && $criteria['status'] !== '') {
            $clauses[] = 'status = :status';
            $bindings['status'] = (string) $criteria['status'];
        }

        // An explicit status set, for the contributor surface: "my drafts" is more than
        // one status, and filtering after loading the rows would have read every author's
        // content first.
        if (isset($criteria['statuses']) && is_array($criteria['statuses'])) {
            $statuses = array_values(array_filter($criteria['statuses'], 'is_string'));

            if ($statuses === []) {
                // An empty set must return nothing, not everything.
                $clauses[] = '1 = 0';
            } else {
                $placeholders = [];

                foreach ($statuses as $index => $status) {
                    $placeholder = 'status_in_' . $index;
                    $placeholders[] = ':' . $placeholder;
                    $bindings[$placeholder] = $status;
                }

                $clauses[] = 'status IN (' . implode(', ', $placeholders) . ')';
            }
        }

        if (isset($criteria['author_id']) && (int) $criteria['author_id'] > 0) {
            $clauses[] = 'author_id = :author_id';
            $bindings['author_id'] = (int) $criteria['author_id'];
        }

        if (($criteria['exclude_trash'] ?? false) === true) {
            $clauses[] = 'status <> :trash_status';
            $bindings['trash_status'] = Content::STATUS_TRASH;
        }

        if (($criteria['published_only'] ?? false) === true) {
            $clauses[] = 'status = :published_status';
            $bindings['published_status'] = Content::STATUS_PUBLISHED;
            $clauses[] = '(published_at IS NULL OR published_at <= :now)';
            $bindings['now'] = $this->now();
        }

        // Restrict to an explicit id set (a taxonomy archive resolves its content
        // ids first, then asks Content for those rows). Placeholders come from a
        // validated integer list, never caller strings.
        if (isset($criteria['ids']) && is_array($criteria['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $criteria['ids']), static fn (int $id): bool => $id > 0));

            if ($ids === []) {
                // An empty id set must return nothing, not everything.
                $clauses[] = '1 = 0';
            } else {
                $placeholders = [];

                foreach ($ids as $index => $id) {
                    $placeholder = 'id_in_' . $index;
                    $placeholders[] = ':' . $placeholder;
                    $bindings[$placeholder] = $id;
                }

                $clauses[] = 'id IN (' . implode(', ', $placeholders) . ')';
            }
        }

        // Title/plain-text search. Searching `content_plain` avoids stripping HTML
        // at query time, which is why that column exists.
        //
        // Two strategies:
        //   fulltext - `MATCH … AGAINST` on ft_contents_search, index-backed.
        //   like     - the portable fallback; a leading wildcard forces a scan.
        // The caller decides (see the Search module), because the repository must not
        // probe the schema on every query.
        if (isset($criteria['search']) && is_string($criteria['search']) && trim($criteria['search']) !== '') {
            $term = trim($criteria['search']);
            $mode = $criteria['search_mode'] ?? 'like';

            if ($mode === 'fulltext') {
                $boolean = $this->toBooleanQuery($term);

                if ($boolean === null) {
                    // Nothing indexable (too short, or only operators): an empty MATCH
                    // would match nothing, so use LIKE rather than silently returning
                    // zero rows.
                    $clauses[] = $this->likeClause($term, $bindings);
                } else {
                    $clauses[] = 'MATCH(title, content_plain) AGAINST (:search_bool IN BOOLEAN MODE)';
                    $bindings['search_bool'] = $boolean;
                }
            } else {
                $clauses[] = $this->likeClause($term, $bindings);
            }
        }

        return ['WHERE ' . implode(' AND ', $clauses), $bindings];
    }

    /**
     * A word-wise LIKE clause.
     *
     * Words are OR-ed, matching the boolean-mode strategy. Searching the literal
     * phrase instead would make the two strategies disagree about the same query: a
     * visitor typing `red boat` expects either word to match, not the exact string.
     *
     * `%` and `_` are stripped from each word rather than escaped, so a visitor cannot
     * turn the search into a wildcard scan.
     *
     * @param array<string, mixed> $bindings passed by reference, appended in place
     */
    private function likeClause(string $term, array &$bindings): string
    {
        $words = preg_split('/\s+/u', $term) ?: [];
        $parts = [];
        $index = 0;

        foreach ($words as $word) {
            $clean = preg_replace('/[+\-><()~*"@%_]+/u', '', $word) ?? '';
            $clean = trim($clean);

            if ($clean === '') {
                continue;
            }

            $titleKey = 'search_like_t_' . $index;
            $plainKey = 'search_like_p_' . $index;

            // Native prepares forbid reusing one named placeholder in a statement, so
            // each column gets its own name.
            $bindings[$titleKey] = '%' . $clean . '%';
            $bindings[$plainKey] = '%' . $clean . '%';

            $parts[] = '(title LIKE :' . $titleKey . ' OR content_plain LIKE :' . $plainKey . ')';
            $index++;
        }

        if ($parts === []) {
            // Only operators were supplied. Match nothing rather than everything.
            return '1 = 0';
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * Turn a user phrase into a BOOLEAN MODE query.
     *
     * Each word becomes a prefix term (`word*`), which reproduces the partial-word
     * behaviour users expect from a search box. MySQL operators are stripped so a
     * visitor cannot inject `+`, `-`, `>` or a quoted phrase and change the meaning
     * of the query.
     *
     * Returns null when nothing usable survives, so the caller can fall back.
     */
    private function toBooleanQuery(string $term): ?string
    {
        // No minimum is enforced here: the Search module decides between fulltext and
        // LIKE based on the configured token window, so this method only runs for
        // terms it has already established are indexable.
        $minimumLength = 1;

        $words = preg_split('/\s+/u', $term) ?: [];
        $parts = [];

        foreach ($words as $word) {
            $clean = preg_replace('/[+\-><()~*"@]+/u', '', $word) ?? '';
            $clean = trim($clean);

            if ($clean === '' || mb_strlen($clean) < $minimumLength) {
                continue;
            }

            $parts[] = $clean . '*';
        }

        if ($parts === []) {
            return null;
        }

        // Space-separated terms are OR-ed in boolean mode; that matches how a simple
        // search box behaves.
        return implode(' ', array_unique($parts));
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function orderClause(array $criteria): string
    {
        return match ($criteria['order'] ?? 'newest') {
            'oldest' => ' ORDER BY published_at IS NULL, published_at ASC, id ASC',
            'title' => ' ORDER BY title ASC, id ASC',
            'updated' => ' ORDER BY updated_at DESC, id DESC',
            default => ' ORDER BY published_at IS NULL, published_at DESC, id DESC',
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Content
    {
        return new Content(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            type: (string) $row['type'],
            authorId: (int) $row['author_id'],
            title: (string) $row['title'],
            slug: (string) $row['slug'],
            excerpt: isset($row['excerpt']) ? (string) $row['excerpt'] : null,
            contentJson: isset($row['content_json']) ? (string) $row['content_json'] : null,
            contentHtml: isset($row['content_html']) ? (string) $row['content_html'] : null,
            contentPlain: isset($row['content_plain']) ? (string) $row['content_plain'] : null,
            status: (string) $row['status'],
            featuredMediaId: isset($row['featured_media_id']) ? (int) $row['featured_media_id'] : null,
            commentStatus: (string) $row['comment_status'],
            publishedAt: $this->toDate($row['published_at'] ?? null),
            scheduledAt: $this->toDate($row['scheduled_at'] ?? null),
            createdAt: $this->toDate($row['created_at']) ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
            updatedAt: $this->toDate($row['updated_at']) ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSummary(array $row): ContentSummary
    {
        return new ContentSummary(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            type: (string) $row['type'],
            authorId: (int) $row['author_id'],
            title: (string) $row['title'],
            slug: (string) $row['slug'],
            excerpt: isset($row['excerpt']) ? (string) $row['excerpt'] : null,
            featuredMediaId: isset($row['featured_media_id']) ? (int) $row['featured_media_id'] : null,
            status: (string) $row['status'],
            publishedAt: $this->toDate($row['published_at'] ?? null),
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

    private function format(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
