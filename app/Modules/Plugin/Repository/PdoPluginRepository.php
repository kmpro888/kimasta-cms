<?php

declare(strict_types=1);

namespace CMS\Modules\Plugin\Repository;

use CMS\Core\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of plugin state.
 */
final class PdoPluginRepository implements PluginRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function install(string $name, string $version, array $manifest): void
    {
        $now = $this->now();

        $this->connection->affect(
            'INSERT INTO plugins (name, version, manifest_json, installed_at, created_at, updated_at) '
            . 'VALUES (:name, :version, :manifest, :now, :now2, :now3) '
            . 'ON DUPLICATE KEY UPDATE version = VALUES(version), manifest_json = VALUES(manifest_json), '
            . 'updated_at = VALUES(updated_at)',
            [
                'name' => $name,
                'version' => $version,
                'manifest' => (string) json_encode($manifest),
                'now' => $now,
                'now2' => $now,
                'now3' => $now,
            ]
        );
    }

    public function uninstall(string $name): bool
    {
        // Activation rows go first: the foreign key is on site_id only, so plugin rows
        // would otherwise be orphaned pointing at a plugin that no longer exists.
        $this->connection->affect('DELETE FROM site_plugins WHERE plugin_name = :name', ['name' => $name]);
        $this->connection->affect('DELETE FROM plugin_migrations WHERE plugin_name = :name', ['name' => $name]);

        return $this->connection->affect('DELETE FROM plugins WHERE name = :name', ['name' => $name]) > 0;
    }

    public function isInstalled(string $name): bool
    {
        $row = $this->connection->selectOne(
            'SELECT 1 AS present FROM plugins WHERE name = :name LIMIT 1',
            ['name' => $name]
        );

        return $row !== null;
    }

    public function installedVersions(): array
    {
        $rows = $this->connection->select('SELECT name, version FROM plugins');

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['name']] = (string) $row['version'];
        }

        return $out;
    }

    public function setActive(int $siteId, string $name, bool $active): void
    {
        $now = $this->now();

        $this->connection->affect(
            'INSERT INTO site_plugins (site_id, plugin_name, is_active, activated_at, created_at, updated_at) '
            . 'VALUES (:site_id, :name, :active, :activated_at, :created_at, :updated_at) '
            . 'ON DUPLICATE KEY UPDATE is_active = VALUES(is_active), '
            . 'activated_at = VALUES(activated_at), updated_at = VALUES(updated_at)',
            [
                'site_id' => $siteId,
                'name' => $name,
                'active' => $active ? 1 : 0,
                'activated_at' => $active ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function isActiveFor(int $siteId, string $name): bool
    {
        $row = $this->connection->selectOne(
            'SELECT 1 AS present FROM site_plugins '
            . 'WHERE site_id = :site_id AND plugin_name = :name AND is_active = 1 LIMIT 1',
            ['site_id' => $siteId, 'name' => $name]
        );

        return $row !== null;
    }

    public function activeFor(int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT plugin_name FROM site_plugins WHERE site_id = :site_id AND is_active = 1 '
            . 'ORDER BY plugin_name ASC',
            ['site_id' => $siteId]
        );

        return array_map(static fn (array $row): string => (string) $row['plugin_name'], $rows);
    }

    public function sitesFor(string $name): array
    {
        $rows = $this->connection->select(
            'SELECT site_id FROM site_plugins WHERE plugin_name = :name AND is_active = 1 ORDER BY site_id ASC',
            ['name' => $name]
        );

        return array_map(static fn (array $row): int => (int) $row['site_id'], $rows);
    }

    public function appliedMigrations(string $name): array
    {
        $rows = $this->connection->select(
            'SELECT migration FROM plugin_migrations WHERE plugin_name = :name ORDER BY migration ASC',
            ['name' => $name]
        );

        return array_map(static fn (array $row): string => (string) $row['migration'], $rows);
    }

    public function recordMigration(string $name, string $migration): void
    {
        $this->connection->affect(
            'INSERT INTO plugin_migrations (plugin_name, migration, applied_at) '
            . 'VALUES (:name, :migration, :applied_at) '
            . 'ON DUPLICATE KEY UPDATE applied_at = VALUES(applied_at)',
            ['name' => $name, 'migration' => $migration, 'applied_at' => $this->now()]
        );
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
