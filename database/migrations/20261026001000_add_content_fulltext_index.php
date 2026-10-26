<?php

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;

/**
 * FULLTEXT index for content search.
 *
 * Search previously used `(title LIKE ? OR content_plain LIKE ?)`, which cannot use
 * an index: a leading wildcard forces a full scan of every row on the site. On a
 * shared host with a few thousand posts that is the slowest query in the product.
 *
 * A FULLTEXT index over (title, content_plain) lets `MATCH … AGAINST` do the work.
 *
 * `content_plain` exists precisely for this: it holds the rendered text with markup
 * stripped, so search never has to strip HTML at query time.
 *
 * The Search module detects this index and falls back to LIKE when it is absent, so
 * the platform still works on a server where FULLTEXT is unavailable (for example a
 * very old MyISAM-only configuration or a managed host that forbids it).
 */
return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        // IF NOT EXISTS is not available for ADD FULLTEXT on MySQL 8 in every
        // variant, so existence is checked explicitly. Re-running a migration is a
        // no-op rather than an error.
        if ($this->indexExists($db, 'contents', 'ft_contents_search')) {
            return;
        }

        $db->statement(
            'ALTER TABLE `contents` ADD FULLTEXT KEY `ft_contents_search` (`title`, `content_plain`)'
        );
    }

    public function down(Connection $db): void
    {
        if (!$this->indexExists($db, 'contents', 'ft_contents_search')) {
            return;
        }

        $db->statement('ALTER TABLE `contents` DROP INDEX `ft_contents_search`');
    }

    private function indexExists(Connection $db, string $table, string $index): bool
    {
        $row = $db->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.statistics '
            . 'WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index',
            ['table' => $table, 'index' => $index]
        );

        return (int) ($row['total'] ?? 0) > 0;
    }
};
