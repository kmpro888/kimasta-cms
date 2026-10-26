<?php

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;

/**
 * Comment threads.
 *
 * Design notes:
 *
 *  - `parent_id` with an explicit `depth` column rather than walking up the tree at
 *    read time. Depth is bounded at write time, so a thread can never become an
 *    unbounded chain that needs recursion to render and cannot be paginated.
 *  - `author_name` is denormalised from the user at submission. A display name change
 *    must not silently rewrite history in old threads.
 *  - `status` covers pending / approved / spam / trash. Spam is a distinct state from
 *    trash so a moderator's decision is not confused with a deletion.
 *  - `contents.comment_count` is not stored: an approved count derived on demand is
 *    always correct, whereas a denormalised counter drifts the first time a write
 *    path forgets it. The cost is one indexed COUNT per content row, and the comment
 *    count is only read on pages that show it.
 */
return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS `comments` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                content_id BIGINT UNSIGNED NOT NULL,
                parent_id BIGINT UNSIGNED NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                author_name VARCHAR(190) NOT NULL,
                body TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'pending\',
                depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_comments_content (site_id, content_id, status, created_at),
                KEY idx_comments_status (site_id, status),
                KEY idx_comments_parent (parent_id),
                CONSTRAINT fk_comments_content FOREIGN KEY (content_id)
                    REFERENCES `contents` (id) ON DELETE CASCADE,
                CONSTRAINT fk_comments_site FOREIGN KEY (site_id)
                    REFERENCES `sites` (id) ON DELETE CASCADE,
                CONSTRAINT fk_comments_parent FOREIGN KEY (parent_id)
                    REFERENCES `comments` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(Connection $db): void
    {
        $db->statement('DROP TABLE IF EXISTS `comments`');
    }
};
