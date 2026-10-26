<?php

declare(strict_types=1);

namespace CMS\Core\Database\Migrations;

use CMS\Core\Database\Connection;

/**
 * Reads and writes the `migrations` tracking table.
 *
 * The table is created lazily on first use because it cannot be created by
 * a migration that itself needs the table to be tracked.
 */
final class MigrationHistory
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = 'migrations',
    ) {
    }

    public function ensureTable(): void
    {
        $this->connection->statement(
            'CREATE TABLE IF NOT EXISTS ' . $this->quotedTable() . ' (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INT UNSIGNED NOT NULL,
                executed_at DATETIME NOT NULL,
                UNIQUE KEY uq_migration (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return list<string> */
    public function executed(): array
    {
        $rows = $this->connection->select(
            'SELECT migration FROM ' . $this->quotedTable() . ' ORDER BY id ASC'
        );

        return array_map(
            static fn (array $row): string => (string) $row['migration'],
            $rows
        );
    }

    public function has(string $migration): bool
    {
        return $this->connection->selectOne(
            'SELECT id FROM ' . $this->quotedTable() . ' WHERE migration = ? LIMIT 1',
            [$migration]
        ) !== null;
    }

    public function record(string $migration, int $batch): void
    {
        $this->connection->statement(
            'INSERT INTO ' . $this->quotedTable() . ' (migration, batch, executed_at) VALUES (?, ?, ?)',
            [$migration, $batch, gmdate('Y-m-d H:i:s')]
        );
    }

    public function remove(string $migration): void
    {
        $this->connection->statement(
            'DELETE FROM ' . $this->quotedTable() . ' WHERE migration = ?',
            [$migration]
        );
    }

    public function latestBatch(): int
    {
        $value = $this->connection->scalar(
            'SELECT MAX(batch) FROM ' . $this->quotedTable()
        );

        return $value === null ? 0 : (int) $value;
    }

    /** @return list<array{migration: string, batch: int}> */
    public function batch(int $batch): array
    {
        return $this->connection->select(
            'SELECT migration, batch FROM ' . $this->quotedTable() . ' WHERE batch = ? ORDER BY id DESC',
            [$batch]
        );
    }

    private function quotedTable(): string
    {
        return '`' . str_replace('`', '', $this->table) . '`';
    }
}