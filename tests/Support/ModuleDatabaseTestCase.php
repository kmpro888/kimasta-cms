<?php

declare(strict_types=1);

namespace Tests\Support;

use CMS\Core\Database\Connection;

/**
 * Base class for tests that exercise Site or Identity against real MySQL.
 *
 * The module migrations are applied on first use and every test starts from an
 * empty set of module tables, so tests never depend on each other or on a
 * developer's leftover rows.
 *
 * Truncation is guarded by DatabaseTestCase's testing-database name check.
 */
abstract class ModuleDatabaseTestCase extends DatabaseTestCase
{
    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$schemaReady) {
            $this->installModuleMigrations();
            self::$schemaReady = true;
        }

        $this->truncateModuleTables();
    }

    private function installModuleMigrations(): void
    {
        $files = [
            '20261025000300_create_site_tables.php',
            '20261025000200_create_identity_tables.php',
            '20261025000400_create_content_tables.php',
            '20261025000500_create_media_tables.php',
            '20261025000600_create_taxonomy_tables.php',
            '20261025000700_create_revision_tables.php',
            '20261025000800_create_workflow_logs_table.php',
            '20261025000900_create_seo_tables.php',
            '20261026001000_add_content_fulltext_index.php',
            '20261026001100_create_comments_table.php',
            '20261026001200_create_plugin_tables.php',
            '20261026001300_create_advertising_tables.php',
            '20261026001400_create_notification_tables.php',
            '20261026001500_create_migration_tables.php',
        ];

        foreach ($files as $file) {
            /** @var object $migration */
            $migration = require CMS_TEST_ROOT . '/database/migrations/' . $file;

            if (!method_exists($migration, 'up')) {
                self::fail('Migration has no up() method: ' . $file);
            }

            $migration->up($this->db);
        }
    }

    private function truncateModuleTables(): void
    {
        // Child tables first; FK checks are disabled as well so the order can
        // never make a delete fail.
        $this->db->statement('SET FOREIGN_KEY_CHECKS = 0');

        $tables = [
            'password_resets',
            'user_roles',
            'role_capabilities',
            'capabilities',
            'roles',
            'users',
            'comments',
            'example_notes',
            'migration_logs',
            'migration_maps',
            'notification_outbox',
            'notifications',
            'ad_rules',
            'ads',
            'plugin_migrations',
            'site_plugins',
            'plugins',
            'content_seo',
            'redirects',
            'workflow_logs',
            'content_revisions',
            'content_autosaves',
            'content_terms',
            'terms',
            'taxonomies',
            'media_variants',
            'media',
            'url_registry',
            'contents',
            'site_settings',
            'site_domains',
            'sites',
        ];

        foreach ($tables as $table) {
            // A plugin-owned table only exists once that plugin has been installed, so a
            // missing table is expected rather than an error. Without this the whole
            // suite fails the moment one plugin table is listed here.
            if (!$this->tableExists($table)) {
                continue;
            }

            // DELETE only, deliberately without `ALTER TABLE ... AUTO_INCREMENT = 1`.
            //
            // Resetting the counter is the expensive half: it forces a data-dictionary
            // operation per table, and over 41 tables that cost 2.6s *per test* -- the
            // single reason the suite took twenty minutes. TRUNCATE is not the answer
            // either: measuring it over the same 41 tables took 8.5s, because InnoDB
            // rebuilds the tablespace.
            //
            // Predicting ids was the only reason to reset the counter, and these tests use
            // the ids they are given rather than assuming values. Monotonically growing ids
            // across a run are harmless.
            $this->db->statement('DELETE FROM `' . $table . '`');
        }

        $this->db->statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function tableExists(string $table): bool
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = :name',
            ['name' => $table]
        );

        return (int) ($row['total'] ?? 0) > 0;
    }

    /**
     * Convenience: create a site row directly and return its id.
     */
    protected function createSiteRow(string $slug, string $domain, string $status = 'active'): int
    {
        $now = gmdate('Y-m-d H:i:s');

        // A previous test that failed before its cleanup can leave this row behind, and the
        // primary-domain unique key then makes every later setUp fail. Clearing it first makes
        // the harness idempotent instead of order-dependent.
        $this->db->statement('DELETE FROM sites WHERE primary_domain = :domain', ['domain' => $domain]);
        $this->db->statement('DELETE FROM site_domains WHERE domain = :domain', ['domain' => $domain]);

        // Native prepared statements (EMULATE_PREPARES=false) forbid reusing the
        // same named placeholder twice, so created/updated get distinct names.
        return $this->db->insert(
            'INSERT INTO sites (name, slug, status, primary_domain, default_locale, timezone, base_url, created_at, updated_at) '
            . 'VALUES (:name, :slug, :status, :domain, :locale, :tz, :url, :created_at, :updated_at)',
            [
                'name' => ucfirst($slug),
                'slug' => $slug,
                'status' => $status,
                'domain' => $domain,
                'locale' => 'en',
                'tz' => 'UTC',
                'url' => 'https://' . $domain,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    protected function mapDomain(int $siteId, string $domain, bool $isPrimary = false): void
    {
        $this->db->statement(
            'INSERT INTO site_domains (site_id, domain, is_primary, created_at) VALUES (:site_id, :domain, :primary, :now)',
            [
                'site_id' => $siteId,
                'domain' => $domain,
                'primary' => $isPrimary ? 1 : 0,
                'now' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }
}
