<?php

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;

/**
 * Bootstrap migration: creates the migration tracking table.
 *
 * The runner also ensures this table exists, so this migration mainly keeps
 * an auditable first entry in `migrations` and demonstrates the naming and
 * return-format contract for later agents.
 */
return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS `migrations` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INT UNSIGNED NOT NULL,
                executed_at DATETIME NOT NULL,
                UNIQUE KEY uq_migration (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(Connection $db): void
    {
        // The tracking table must survive rollback, otherwise history is lost.
    }
};
