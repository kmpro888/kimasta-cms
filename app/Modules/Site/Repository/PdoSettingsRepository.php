<?php

declare(strict_types=1);

namespace CMS\Modules\Site\Repository;

use CMS\Core\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of per-site settings.
 *
 * Reads for a hot request hit the compiled autoload file first; only a cache
 * miss reaches these queries, and each one is indexed by (site_id, ...).
 */
final class PdoSettingsRepository implements SettingsRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function autoloaded(int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT namespace, setting_key, setting_value FROM site_settings '
            . 'WHERE site_id = :site_id AND autoload = 1 ORDER BY namespace ASC, setting_key ASC',
            ['site_id' => $siteId]
        );

        $grouped = [];

        foreach ($rows as $row) {
            $namespace = (string) $row['namespace'];
            $grouped[$namespace][(string) $row['setting_key']] = $this->decode($row['setting_value']);
        }

        return $grouped;
    }

    public function forNamespace(int $siteId, string $namespace): array
    {
        $rows = $this->connection->select(
            'SELECT setting_key, setting_value FROM site_settings '
            . 'WHERE site_id = :site_id AND namespace = :namespace',
            ['site_id' => $siteId, 'namespace' => $namespace]
        );

        $values = [];

        foreach ($rows as $row) {
            $values[(string) $row['setting_key']] = $this->decode($row['setting_value']);
        }

        return $values;
    }

    public function find(int $siteId, string $namespace, string $key): mixed
    {
        $row = $this->connection->selectOne(
            'SELECT setting_value FROM site_settings '
            . 'WHERE site_id = :site_id AND namespace = :namespace AND setting_key = :setting_key LIMIT 1',
            ['site_id' => $siteId, 'namespace' => $namespace, 'setting_key' => $key]
        );

        return $row === null ? null : $this->decode($row['setting_value']);
    }

    public function put(
        int $siteId,
        string $namespace,
        string $key,
        string $encodedValue,
        bool $autoload,
    ): void {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        // Single statement upsert: the unique key on
        // (site_id, namespace, setting_key) makes this race-free.
        $this->connection->affect(
            'INSERT INTO site_settings (site_id, namespace, setting_key, setting_value, autoload, updated_at) '
            . 'VALUES (:site_id, :namespace, :setting_key, :setting_value, :autoload, :updated_at) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), '
            . 'autoload = VALUES(autoload), updated_at = VALUES(updated_at)',
            [
                'site_id' => $siteId,
                'namespace' => $namespace,
                'setting_key' => $key,
                'setting_value' => $encodedValue,
                'autoload' => $autoload ? 1 : 0,
                'updated_at' => $now,
            ]
        );
    }

    public function forget(int $siteId, string $namespace, string $key): void
    {
        $this->connection->affect(
            'DELETE FROM site_settings '
            . 'WHERE site_id = :site_id AND namespace = :namespace AND setting_key = :setting_key',
            ['site_id' => $siteId, 'namespace' => $namespace, 'setting_key' => $key]
        );
    }

    private function decode(mixed $raw): mixed
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        // A value that is not valid JSON was written by something other than
        // this module; surface it verbatim rather than silently nulling it.
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }
}
