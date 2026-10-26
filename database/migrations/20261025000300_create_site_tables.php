<?php

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;

/**
 * Multisite schema: sites, site_domains, site_settings.
 *
 * Owns these three tables. Follows docs/02-Database-Schema.md exactly.
 *
 * Multisite rules encoded here:
 *  - a domain is globally unique: one host can only ever map to one site
 *  - site_domains.domain is the primary lookup column, so it is indexed
 *    through its unique key; unknown-host resolution is index-backed
 *  - site_settings is unique per (site_id, namespace, setting_key) and
 *    indexed by (site_id, autoload) for the compiled autoload cache
 *  - deleting a site cascades its domains and settings
 */
return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS `sites` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'active\',
                primary_domain VARCHAR(255) NOT NULL,
                default_locale VARCHAR(20) NOT NULL DEFAULT \'en\',
                timezone VARCHAR(64) NOT NULL DEFAULT \'Asia/Jakarta\',
                base_url VARCHAR(255) NOT NULL DEFAULT \'\',
                active_theme VARCHAR(120) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_sites_primary_domain (primary_domain),
                UNIQUE KEY uq_sites_slug (slug),
                KEY idx_sites_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `site_domains` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                domain VARCHAR(255) NOT NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_site_domains_domain (domain),
                KEY idx_site_domains_site (site_id),
                CONSTRAINT fk_site_domains_site
                    FOREIGN KEY (site_id)
                    REFERENCES `sites` (id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `site_settings` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                namespace VARCHAR(80) NOT NULL,
                setting_key VARCHAR(120) NOT NULL,
                setting_value LONGTEXT NULL,
                autoload TINYINT(1) NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_site_setting (site_id, namespace, setting_key),
                KEY idx_site_settings_autoload (site_id, autoload),
                CONSTRAINT fk_site_settings_site
                    FOREIGN KEY (site_id)
                    REFERENCES `sites` (id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(Connection $db): void
    {
        $db->statement('DROP TABLE IF EXISTS `site_settings`');
        $db->statement('DROP TABLE IF EXISTS `site_domains`');
        $db->statement('DROP TABLE IF EXISTS `sites`');
    }
};
