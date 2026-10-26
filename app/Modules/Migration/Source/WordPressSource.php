<?php

declare(strict_types=1);

namespace CMS\Modules\Migration\Source;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Read-only access to a WordPress database.
 *
 * Deliberately a separate object with its own PDO: the source is a **foreign, legacy,
 * untrusted** schema. Reading it through the platform's own `Connection` would blur the
 * boundary between "our data" and "someone else's data", and would let a migration query
 * run inside our transaction.
 *
 * Table names are built from a configured prefix and are validated against a strict
 * pattern, because a prefix is concatenated into SQL — it is the one place in this module
 * where an identifier is interpolated.
 */
final class WordPressSource
{
    private const PREFIX_PATTERN = '/^[A-Za-z0-9_]{0,40}$/';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix = 'wp_',
    ) {
        if (preg_match(self::PREFIX_PATTERN, $this->prefix) !== 1) {
            throw new RuntimeException('Invalid WordPress table prefix.');
        }
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Does the source look like a WordPress install?
     */
    public function isAvailable(): bool
    {
        try {
            return $this->count('posts') >= 0;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function postsBatch(int $after, int $limit, string $postType = 'post'): array
    {
        return $this->select(
            'SELECT ID, post_author, post_date, post_date_gmt, post_modified, post_title, post_name, '
            . 'post_excerpt, post_content, post_status, post_type, comment_status '
            . 'FROM `' . $this->table('posts') . '` '
            . 'WHERE post_type = :type AND ID > :after '
            // Attachments and revisions are separate types and must not be imported as
            // content; `post_type` already excludes them.
            . 'ORDER BY ID ASC LIMIT ' . max(1, $limit),
            ['type' => $postType, 'after' => $after]
        );
    }

    public function countPosts(string $postType = 'post'): int
    {
        return $this->count('posts', 'post_type = :type', ['type' => $postType]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function usersBatch(int $after, int $limit): array
    {
        return $this->select(
            'SELECT ID, user_login, user_email, display_name, user_registered FROM `'
            . $this->table('users') . '` WHERE ID > :after ORDER BY ID ASC LIMIT ' . max(1, $limit),
            ['after' => $after]
        );
    }

    public function countUsers(): int
    {
        return $this->count('users');
    }

    /**
     * Terms joined to their taxonomy, so `category` and `post_tag` are distinguishable.
     *
     * @return list<array<string, mixed>>
     */
    public function termsBatch(int $after, int $limit): array
    {
        return $this->select(
            'SELECT tt.term_taxonomy_id AS tt_id, tt.taxonomy, tt.description, tt.parent, '
            . 't.term_id, t.name, t.slug '
            . 'FROM `' . $this->table('term_taxonomy') . '` tt '
            . 'INNER JOIN `' . $this->table('terms') . '` t ON t.term_id = tt.term_id '
            . 'WHERE tt.term_taxonomy_id > :after AND tt.taxonomy IN (:cat, :tag) '
            . 'ORDER BY tt.term_taxonomy_id ASC LIMIT ' . max(1, $limit),
            ['after' => $after, 'cat' => 'category', 'tag' => 'post_tag']
        );
    }

    public function countTerms(): int
    {
        return $this->count('term_taxonomy', 'taxonomy IN (:cat, :tag)', ['cat' => 'category', 'tag' => 'post_tag']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function attachmentsBatch(int $after, int $limit): array
    {
        return $this->select(
            'SELECT ID, post_title, post_name, post_mime_type, guid, post_date, post_parent '
            . 'FROM `' . $this->table('posts') . '` '
            . 'WHERE post_type = :type AND ID > :after ORDER BY ID ASC LIMIT ' . max(1, $limit),
            ['type' => 'attachment', 'after' => $after]
        );
    }

    public function countAttachments(): int
    {
        return $this->count('posts', 'post_type = :type', ['type' => 'attachment']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function commentsBatch(int $after, int $limit): array
    {
        return $this->select(
            'SELECT comment_ID, comment_post_ID, comment_author, comment_author_email, '
            . 'comment_date, comment_content, comment_approved, comment_parent '
            . 'FROM `' . $this->table('comments') . '` WHERE comment_ID > :after '
            . 'ORDER BY comment_ID ASC LIMIT ' . max(1, $limit),
            ['after' => $after]
        );
    }

    public function countComments(): int
    {
        return $this->count('comments');
    }

    /**
     * All metadata for one post, optionally limited to specific keys.
     *
     * @param list<string> $keys
     *
     * @return array<string, list<string>>
     */
    public function postMeta(int $postId, array $keys = []): array
    {
        $sql = 'SELECT meta_key, meta_value FROM `' . $this->table('postmeta') . '` '
            . 'WHERE post_id = :post_id';
        $bindings = ['post_id' => $postId];

        if ($keys !== []) {
            $placeholders = [];

            foreach (array_values($keys) as $index => $key) {
                $placeholder = 'key_' . $index;
                $placeholders[] = ':' . $placeholder;
                $bindings[$placeholder] = $key;
            }

            $sql .= ' AND meta_key IN (' . implode(', ', $placeholders) . ')';
        }

        $rows = $this->select($sql . ' ORDER BY meta_id ASC', $bindings);

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['meta_key']][] = (string) $row['meta_value'];
        }

        return $out;
    }

    /**
     * Term ids attached to a post, keyed by taxonomy name.
     *
     * @return array<string, list<int>>
     */
    public function postTermIds(int $postId): array
    {
        $rows = $this->select(
            'SELECT tt.taxonomy, tt.term_id '
            . 'FROM `' . $this->table('term_relationships') . '` tr '
            . 'INNER JOIN `' . $this->table('term_taxonomy') . '` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id '
            . 'WHERE tr.object_id = :id',
            ['id' => $postId]
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['taxonomy']][] = (int) $row['term_id'];
        }

        return $out;
    }

    /**
     * A WordPress option value, e.g. `permalink_structure` or `siteurl`.
     */
    public function option(string $name): ?string
    {
        try {
            $rows = $this->select(
                'SELECT option_value FROM `' . $this->table('options') . '` WHERE option_name = :name LIMIT 1',
                ['name' => $name]
            );
        } catch (PDOException) {
            // The options table is optional for this migration; without it the permalink
            // structure is simply unknown and URLs fall back to slug preservation.
            return null;
        }

        return isset($rows[0]['option_value']) ? (string) $rows[0]['option_value'] : null;
    }

    /**
     * @param array<string, mixed> $bindings
     *
     * @return list<array<string, mixed>>
     */
    private function select(string $sql, array $bindings = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function count(string $table, string $where = '', array $bindings = []): int
    {
        $sql = 'SELECT COUNT(*) AS total FROM `' . $this->table($table) . '`'
            . ($where !== '' ? ' WHERE ' . $where : '');

        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return (int) $statement->fetchColumn();
    }

    private function table(string $name): string
    {
        return $this->prefix . $name;
    }
}
