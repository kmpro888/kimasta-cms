<?php

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;

/**
 * Audit trail for privileged actions.
 *
 * Append-only by construction: there is no update and no delete path in the repository, because a
 * log somebody can edit is not evidence. Rows are scoped by `site_id` like every other tenant table,
 * and the actor's name is snapshotted — deleting a user must not erase the record of what they did.
 *
 * No foreign keys to `users`: a row has to survive the user's deletion, and a cascade would quietly
 * remove exactly the history an audit exists to keep.
 */
return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS `audit_log` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                actor_id BIGINT UNSIGNED NULL,
                actor_name VARCHAR(190) NOT NULL,
                action VARCHAR(100) NOT NULL,
                subject_type VARCHAR(60) NULL,
                subject_id BIGINT UNSIGNED NULL,
                context_json LONGTEXT NULL,
                ip VARCHAR(45) NULL,
                created_at DATETIME NOT NULL,
                KEY audit_site_time (site_id, created_at),
                KEY audit_site_action (site_id, action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(Connection $db): void
    {
        $db->statement('DROP TABLE IF EXISTS `audit_log`');
    }
};
