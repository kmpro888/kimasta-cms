<?php

declare(strict_types=1);

namespace CMS\Core\Database\Migrations;

use CMS\Core\Filesystem\FilesystemInterface;

/**
 * Discovers migration files on disk.
 *
 * Naming convention: YYYYMMDDHHMMSS_description.php (frozen). Files are
 * returned sorted by their timestamp prefix, which makes execution order
 * deterministic and independent of filesystem ordering.
 */
final class MigrationRepository
{
    public function __construct(
        private readonly FilesystemInterface $filesystem,
        private readonly string $directory,
    ) {
    }

    /**
     * @return list<array{name: string, path: string, timestamp: string}>
     */
    public function all(): array
    {
        if (!$this->filesystem->isDirectory($this->directory)) {
            return [];
        }

        $entries = scandir($this->filesystem->path($this->directory));

        if ($entries === false) {
            return [];
        }

        $migrations = [];

        foreach ($entries as $entry) {
            if (!str_ends_with($entry, '.php')) {
                continue;
            }

            $name = substr($entry, 0, -4);

            if (preg_match('/^(\\d{14})_([A-Za-z0-9_]+)$/', $name, $matches) !== 1) {
                continue;
            }

            $migrations[] = [
                'name' => $name,
                'path' => $this->directory . '/' . $entry,
                'timestamp' => $matches[1],
            ];
        }

        usort(
            $migrations,
            static fn (array $a, array $b): int => strcmp($a['timestamp'], $b['timestamp'])
        );

        return $migrations;
    }

    public function require(string $name): MigrationInterface
    {
        foreach ($this->all() as $migration) {
            if ($migration['name'] !== $name) {
                continue;
            }

            $instance = require $this->filesystem->path($migration['path']);

            if (!$instance instanceof MigrationInterface) {
                throw new \RuntimeException(
                    sprintf('Migration [%s] must return an instance of MigrationInterface.', $name)
                );
            }

            return $instance;
        }

        throw new \RuntimeException(sprintf('Migration [%s] not found.', $name));
    }
}