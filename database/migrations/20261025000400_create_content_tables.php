<?php

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;

/**
 * Content engine schema: contents + url_registry.
 *
 * Follows docs/02-Database-Schema.md sections 17 and 68.
 *
 * Design notes:
 *  - `status` is VARCHAR, not ENUM, so a workflow migration can extend the
 *    vocabulary without an ALTER on a large table. Validation lives in the
 *    application layer.
 *  - `content_html` and `content_plain` are derived columns. They exist so a
 *    public page never compiles blocks during a view, and so search never has to
 *    strip HTML at query time.
 *  - `url_registry` gives one indexed lookup from a request path to an entity,
 *    which is what makes permalink resolution cheap and collision handling
 *    centralised.
 *  - The unique key on (site_id, type, slug) is what actually guarantees no
 *    duplicate slug under concurrency; the service pre-check only produces a
 *    nicer error.
 */
return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS `contents` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                type VARCHAR(80) NOT NULL,
                author_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(220) NOT NULL,
                excerpt TEXT NULL,
                content_json LONGTEXT NULL,
                content_html LONGTEXT NULL,
                content_plain LONGTEXT NULL,
                status VARCHAR(30) NOT NULL DEFAULT \'draft\',
                featured_media_id BIGINT UNSIGNED NULL,
                comment_status VARCHAR(20) NOT NULL DEFAULT \'open\',
                published_at DATETIME NULL,
                scheduled_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_contents_slug (site_id, type, slug),
                KEY idx_contents_archive (site_id, type, status, published_at),
                KEY idx_contents_author (site_id, author_id, status),
                KEY idx_contents_schedule (site_id, status, scheduled_at),
                CONSTRAINT fk_contents_site FOREIGN KEY (site_id)
                    REFERENCES `sites` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `url_registry` (
                site_id BIGINT UNSIGNED NOT NULL,
                path VARCHAR(500) NOT NULL,
                entity_type VARCHAR(80) NOT NULL,
                entity_id BIGINT UNSIGNED NOT NULL,
                canonical TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (site_id, path),
                KEY idx_url_entity (site_id, entity_type, entity_id),
                CONSTRAINT fk_url_registry_site FOREIGN KEY (site_id)
                    REFERENCES `sites` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(Connection $db): void
    {
        $db->statement('DROP TABLE IF EXISTS `url_registry`');
        $db->statement('DROP TABLE IF EXISTS `contents`');
    }
};
