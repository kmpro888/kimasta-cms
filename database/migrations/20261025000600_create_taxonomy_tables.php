<?php

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;

/**
 * Taxonomy schema: taxonomies, terms, content_terms.
 *
 * Follows docs/02-Database-Schema.md sections 24, 25 and 26.
 *
 * Notes:
 *  - `terms.content_count` is a precomputed counter, so an archive page never
 *    runs COUNT(*). Attach and detach adjust it in the same transaction.
 *  - `content_terms.site_id` is denormalised on purpose: it makes a
 *    tenant-scoped term lookup index-only without joining `terms`.
 *  - The unique key (site_id, taxonomy_id, slug) is what actually prevents a
 *    duplicate slug; the service pre-check only produces a nicer error.
 */
return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS `taxonomies` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(80) NOT NULL,
                content_type VARCHAR(80) NULL,
                hierarchical TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_taxonomy_name (site_id, name, content_type),
                CONSTRAINT fk_taxonomies_site FOREIGN KEY (site_id)
                    REFERENCES `sites` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `terms` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                taxonomy_id BIGINT UNSIGNED NOT NULL,
                parent_id BIGINT UNSIGNED NULL,
                name VARCHAR(150) NOT NULL,
                slug VARCHAR(180) NOT NULL,
                description TEXT NULL,
                content_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_term_slug (site_id, taxonomy_id, slug),
                KEY idx_terms_taxonomy (site_id, taxonomy_id),
                KEY idx_terms_parent (parent_id),
                CONSTRAINT fk_terms_site FOREIGN KEY (site_id)
                    REFERENCES `sites` (id) ON DELETE CASCADE,
                CONSTRAINT fk_terms_taxonomy FOREIGN KEY (taxonomy_id)
                    REFERENCES `taxonomies` (id) ON DELETE CASCADE,
                CONSTRAINT fk_terms_parent FOREIGN KEY (parent_id)
                    REFERENCES `terms` (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `content_terms` (
                content_id BIGINT UNSIGNED NOT NULL,
                term_id BIGINT UNSIGNED NOT NULL,
                site_id BIGINT UNSIGNED NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY (content_id, term_id),
                KEY idx_content_terms_term (site_id, term_id, content_id),
                CONSTRAINT fk_content_terms_content FOREIGN KEY (content_id)
                    REFERENCES `contents` (id) ON DELETE CASCADE,
                CONSTRAINT fk_content_terms_term FOREIGN KEY (term_id)
                    REFERENCES `terms` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(Connection $db): void
    {
        $db->statement('DROP TABLE IF EXISTS `content_terms`');
        $db->statement('DROP TABLE IF EXISTS `terms`');
        $db->statement('DROP TABLE IF EXISTS `taxonomies`');
    }
};
