<?php

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;

/**
 * Media schema: media + media_variants.
 *
 * Follows docs/02-Database-Schema.md sections 29 and 30.
 *
 * Notes:
 *  - `path` stores a site-relative path, not a URL, so a domain change never
 *    requires rewriting rows.
 *  - media_variants is unique per (media_id, variant, format) so re-running the
 *    variant pipeline updates instead of duplicating.
 *  - site_id is on both tables (denormalised on variants) so a variant query is
 *    site-scoped without a join.
 */
return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS `media` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                uploaded_by BIGINT UNSIGNED NOT NULL,
                original_name VARCHAR(255) NULL,
                path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                extension VARCHAR(20) NOT NULL,
                width INT UNSIGNED NULL,
                height INT UNSIGNED NULL,
                filesize BIGINT UNSIGNED NOT NULL,
                alt_text VARCHAR(255) NULL,
                caption TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_media_site_date (site_id, created_at),
                KEY idx_media_user (site_id, uploaded_by),
                CONSTRAINT fk_media_site FOREIGN KEY (site_id)
                    REFERENCES `sites` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `media_variants` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                media_id BIGINT UNSIGNED NOT NULL,
                site_id BIGINT UNSIGNED NOT NULL,
                variant VARCHAR(50) NOT NULL,
                format VARCHAR(20) NOT NULL,
                path VARCHAR(500) NOT NULL,
                width INT UNSIGNED NULL,
                height INT UNSIGNED NULL,
                filesize BIGINT UNSIGNED NULL,
                UNIQUE KEY uq_media_variant (media_id, variant, format),
                KEY idx_media_variant_site (site_id, media_id),
                CONSTRAINT fk_media_variant_media FOREIGN KEY (media_id)
                    REFERENCES `media` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(Connection $db): void
    {
        $db->statement('DROP TABLE IF EXISTS `media_variants`');
        $db->statement('DROP TABLE IF EXISTS `media`');
    }
};
