<?php

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;

/**
 * Notifications: in-app records and an email outbox.
 *
 * Two tables because the two channels have opposite delivery profiles:
 *
 *  - `notifications` is written once and read by the recipient. It is safe to write
 *    synchronously in the request that caused it.
 *  - `notification_outbox` is a **queue drained by cron**. Sending SMTP inside a publish
 *    request would make the editor wait on a remote server and would lose the message
 *    entirely if it timed out. Writing a row and letting cron deliver it decouples the two,
 *    and needs no long-running worker — which the production constraints forbid.
 *
 * `attempts` and `last_error` exist so a failing recipient is retried a bounded number of
 * times and then surfaces, instead of being retried forever or silently dropped.
 */
return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS `notifications` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                channel VARCHAR(20) NOT NULL DEFAULT \'internal\',
                event VARCHAR(80) NOT NULL,
                title VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                content_id BIGINT UNSIGNED NULL,
                read_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                KEY idx_notifications_user (site_id, user_id, read_at, created_at),
                CONSTRAINT fk_notifications_site FOREIGN KEY (site_id)
                    REFERENCES `sites` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `notification_outbox` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                recipient VARCHAR(190) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                event VARCHAR(80) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'pending\',
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                last_error VARCHAR(500) NULL,
                available_at DATETIME NOT NULL,
                sent_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                KEY idx_outbox_due (status, available_at),
                CONSTRAINT fk_outbox_site FOREIGN KEY (site_id)
                    REFERENCES `sites` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(Connection $db): void
    {
        $db->statement('DROP TABLE IF EXISTS `notification_outbox`');
        $db->statement('DROP TABLE IF EXISTS `notifications`');
    }
};
