<?php

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;

/**
 * Example plugin migration.
 *
 * Proves the plugin lifecycle runs its own schema changes: installing this plugin
 * creates a table that core does not know about. Uninstalling leaves it alone — the
 * platform never drops a plugin's data as a side effect.
 */
return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS `example_notes` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                body TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_example_notes_site (site_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(Connection $db): void
    {
        $db->statement('DROP TABLE IF EXISTS `example_notes`');
    }
};
