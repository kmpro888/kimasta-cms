<?php

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;

/**
 * SEO schema: content_seo + redirects.
 *
 * Follows docs/02-Database-Schema.md sections 27 and 35.
 *
 * Notes:
 *  - SEO core fields are explicit columns, never `meta_key`/`meta_value`. That is
 *    the whole point: a meta table would make every SEO read a join or a second
 *    query.
 *  - `redirects` has a unique key on (site_id, source_path), so lookup is an exact
 *    indexed hit and a duplicate source is impossible at the storage layer.
 *  - `hit_count` exists but is not incremented synchronously; see the schema note
 *    about write amplification on high-traffic redirects.
 */
return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS `content_seo` (
                content_id BIGINT UNSIGNED PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                meta_title VARCHAR(255) NULL,
                meta_description VARCHAR(320) NULL,
                canonical_url VARCHAR(500) NULL,
                robots_index TINYINT(1) NOT NULL DEFAULT 1,
                robots_follow TINYINT(1) NOT NULL DEFAULT 1,
                og_title VARCHAR(255) NULL,
                og_description VARCHAR(320) NULL,
                og_image_id BIGINT UNSIGNED NULL,
                schema_type VARCHAR(80) NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_content_seo_site (site_id),
                CONSTRAINT fk_content_seo_content FOREIGN KEY (content_id)
                    REFERENCES `contents` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `redirects` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                source_path VARCHAR(500) NOT NULL,
                target_url VARCHAR(1000) NOT NULL,
                status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
                hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_redirect_source (site_id, source_path),
                CONSTRAINT fk_redirects_site FOREIGN KEY (site_id)
                    REFERENCES `sites` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(Connection $db): void
    {
        $db->statement('DROP TABLE IF EXISTS `redirects`');
        $db->statement('DROP TABLE IF EXISTS `content_seo`');
    }
};
