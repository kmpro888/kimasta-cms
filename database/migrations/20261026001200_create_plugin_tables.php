<?php

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;

/**
 * Plugin platform tables.
 *
 *  plugins           - one row per discovered-then-installed plugin (global, not per
 *                      site: code is shared, activation is not).
 *  site_plugins      - activation state per tenant. A plugin enabled on Site A must
 *                      not run on Site B.
 *  plugin_migrations - which of a plugin's own migrations have run.
 *
 * The `plugins` table stores the manifest JSON as discovered at install time. It is a
 * record of what was installed, not the source of truth — the filesystem manifest is,
 * so an updated plugin file is picked up on rediscovery and the stored copy is only
 * used to detect drift.
 */
return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS `plugins` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                version VARCHAR(40) NOT NULL,
                manifest_json TEXT NOT NULL,
                installed_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_plugins_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `site_plugins` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                plugin_name VARCHAR(190) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 0,
                activated_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_site_plugin (site_id, plugin_name),
                KEY idx_site_plugins_active (site_id, is_active),
                CONSTRAINT fk_site_plugins_site FOREIGN KEY (site_id)
                    REFERENCES `sites` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `plugin_migrations` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                plugin_name VARCHAR(190) NOT NULL,
                migration VARCHAR(190) NOT NULL,
                applied_at DATETIME NOT NULL,
                UNIQUE KEY uq_plugin_migration (plugin_name, migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(Connection $db): void
    {
        $db->statement('DROP TABLE IF EXISTS `plugin_migrations`');
        $db->statement('DROP TABLE IF EXISTS `site_plugins`');
        $db->statement('DROP TABLE IF EXISTS `plugins`');
    }
};
