<?php

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;

/**
 * Advertising: placements and targeting rules.
 *
 *  ads       - one row per creative: where it may appear, what it contains, when it runs.
 *  ad_rules  - targeting conditions, one row per condition.
 *
 * Rules are rows rather than columns because the set of targeting dimensions grows
 * (device, content type, category, path…) while the ad record stays the same shape. A
 * fixed set of nullable columns would either need a migration per dimension or a generic
 * `meta` blob, and a generic blob is the thing this project forbids for core fields.
 *
 * There is deliberately **no impression or click counter column**. A row write per
 * visitor view is exactly the load a shared host cannot take; counting belongs to an
 * analytics provider, which this platform already supports.
 */
return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS `ads` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(190) NOT NULL,
                placement VARCHAR(40) NOT NULL,
                body_html TEXT NOT NULL,
                target_url VARCHAR(1000) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                priority INT NOT NULL DEFAULT 0,
                starts_at DATETIME NULL,
                ends_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_ads_placement (site_id, placement, is_active),
                KEY idx_ads_window (site_id, starts_at, ends_at),
                CONSTRAINT fk_ads_site FOREIGN KEY (site_id)
                    REFERENCES `sites` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `ad_rules` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ad_id BIGINT UNSIGNED NOT NULL,
                rule_type VARCHAR(40) NOT NULL,
                rule_value VARCHAR(190) NOT NULL,
                KEY idx_ad_rules_ad (ad_id, rule_type),
                CONSTRAINT fk_ad_rules_ad FOREIGN KEY (ad_id)
                    REFERENCES `ads` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(Connection $db): void
    {
        $db->statement('DROP TABLE IF EXISTS `ad_rules`');
        $db->statement('DROP TABLE IF EXISTS `ads`');
    }
};
