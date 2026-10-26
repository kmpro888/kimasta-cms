<?php

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;

/**
 * Migration bookkeeping.
 *
 *  migration_maps - source → target identity, unique per (site, type, source id).
 *                   This is what makes a rerun idempotent: a batch that already ran
 *                   finds its rows here and skips them instead of duplicating content.
 *  migration_logs - every skipped or transformed item with a reason.
 *
 * `migration_logs` is not a debugging nicety. The task pack forbids silently discarding
 * unknown metadata, so anything not carried across must leave a row stating the source id,
 * the field, and why. Without that table the only options are "lose it quietly" or "abort".
 */
return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS `migration_maps` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                source_type VARCHAR(40) NOT NULL,
                source_id BIGINT UNSIGNED NOT NULL,
                target_type VARCHAR(40) NOT NULL,
                target_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_migration_map (site_id, source_type, source_id),
                KEY idx_migration_map_target (site_id, target_type, target_id),
                CONSTRAINT fk_migration_maps_site FOREIGN KEY (site_id)
                    REFERENCES `sites` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `migration_logs` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                run_id VARCHAR(40) NOT NULL,
                level VARCHAR(20) NOT NULL,
                source_type VARCHAR(40) NOT NULL,
                source_id BIGINT UNSIGNED NOT NULL,
                field VARCHAR(190) NULL,
                reason VARCHAR(500) NOT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_migration_logs_run (site_id, run_id, level),
                KEY idx_migration_logs_source (site_id, source_type, source_id),
                CONSTRAINT fk_migration_logs_site FOREIGN KEY (site_id)
                    REFERENCES `sites` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(Connection $db): void
    {
        $db->statement('DROP TABLE IF EXISTS `migration_logs`');
        $db->statement('DROP TABLE IF EXISTS `migration_maps`');
    }
};
