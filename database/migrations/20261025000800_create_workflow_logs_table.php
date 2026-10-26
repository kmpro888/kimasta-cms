<?php

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;

/**
 * Editorial audit trail: workflow_logs.
 *
 * Follows docs/02-Database-Schema.md section 32.
 *
 * Every status transition writes one row here, including the system-driven ones
 * performed by the scheduler (user_id 0). An editorial history with gaps is worse
 * than no history, because it looks complete.
 */
return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS `workflow_logs` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                content_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                from_status VARCHAR(30) NULL,
                to_status VARCHAR(30) NOT NULL,
                note TEXT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_workflow_content (site_id, content_id, created_at),
                KEY idx_workflow_user (site_id, user_id, created_at),
                CONSTRAINT fk_workflow_log_content FOREIGN KEY (content_id)
                    REFERENCES `contents` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(Connection $db): void
    {
        $db->statement('DROP TABLE IF EXISTS `workflow_logs`');
    }
};
