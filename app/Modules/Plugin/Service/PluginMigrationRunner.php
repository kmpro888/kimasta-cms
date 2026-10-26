<?php

declare(strict_types=1);

namespace CMS\Modules\Plugin\Service;

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;
use CMS\Modules\Plugin\Repository\PluginRepositoryInterface;
use Throwable;

/**
 * Runs a plugin's own migrations.
 *
 * Plugin migrations live in `plugins/{name}/migrations/*.php` and return an object with
 * `up(Connection)` and `down(Connection)` — the same shape as core migrations, so there
 * is one migration idiom in the project rather than two.
 *
 * Applied migrations are recorded per plugin in `plugin_migrations`, so reinstalling a
 * plugin does not re-run schema changes, and a plugin can be upgraded independently of
 * the core.
 */
final class PluginMigrationRunner
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PluginRepositoryInterface $repository,
    ) {
    }

    /**
     * Run every not-yet-applied migration for a plugin.
     *
     * @return list<string> the migrations that ran
     *
     * @throws Throwable when a migration fails; the caller decides whether that aborts
     *                   the install
     */
    public function run(string $pluginName, string $migrationsPath): array
    {
        if (!is_dir($migrationsPath)) {
            return [];
        }

        $files = glob($migrationsPath . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);

        $applied = $this->repository->appliedMigrations($pluginName);
        $ran = [];

        foreach ($files as $file) {
            $migration = basename($file);

            if (in_array($migration, $applied, true)) {
                continue;
            }

            /** @var mixed $instance */
            $instance = require $file;

            if (!$instance instanceof MigrationInterface) {
                throw new \RuntimeException(
                    'Plugin migration must return a MigrationInterface: ' . $pluginName . '/' . $migration
                );
            }

            $instance->up($this->connection);
            $this->repository->recordMigration($pluginName, $migration);
            $ran[] = $migration;
        }

        return $ran;
    }

    /**
     * Run a plugin's migrations in reverse, newest first.
     *
     * Only used by an explicit uninstall-with-data; the normal uninstall leaves a
     * plugin's tables alone.
     *
     * @return list<string>
     */
    public function rollback(string $pluginName, string $migrationsPath): array
    {
        if (!is_dir($migrationsPath)) {
            return [];
        }

        $applied = $this->repository->appliedMigrations($pluginName);

        // Newest first: a migration may depend on one applied before it.
        $applied = array_reverse($applied);

        $rolledBack = [];

        foreach ($applied as $migration) {
            $file = $migrationsPath . DIRECTORY_SEPARATOR . $migration;

            if (!is_file($file)) {
                continue;
            }

            /** @var mixed $instance */
            $instance = require $file;

            if (!$instance instanceof MigrationInterface) {
                continue;
            }

            $instance->down($this->connection);
            $rolledBack[] = $migration;
        }

        return $rolledBack;
    }
}
