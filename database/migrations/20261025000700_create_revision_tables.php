<?php

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;

/**
 * Revision and autosave schema.
 *
 * Follows docs/02-Database-Schema.md sections 22 and 23.
 *
 * Two deliberate separations:
 *  - Autosaves live in their own table, NOT in content_revisions. An autosave
 *    fires frequently and must never consume the revision budget.
 *  - content_autosaves is keyed by (content_id, user_id), so one user has exactly
 *    one live draft per post and repeated autosaves replace rather than
 *    accumulate.
 */
return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS `content_revisions` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                content_id BIGINT UNSIGNED NOT NULL,
                created_by BIGINT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                excerpt TEXT NULL,
                content_json LONGTEXT NULL,
                content_html LONGTEXT NULL,
                revision_number INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_revision_content (site_id, content_id, created_at),
                CONSTRAINT fk_revision_content FOREIGN KEY (content_id)
                    REFERENCES `contents` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `content_autosaves` (
                content_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                site_id BIGINT UNSIGNED NOT NULL,
                content_json LONGTEXT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (content_id, user_id),
                CONSTRAINT fk_autosave_content FOREIGN KEY (content_id)
                    REFERENCES `contents` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(Connection $db): void
    {
        $db->statement('DROP TABLE IF EXISTS `content_autosaves`');
        $db->statement('DROP TABLE IF EXISTS `content_revisions`');
    }
};
