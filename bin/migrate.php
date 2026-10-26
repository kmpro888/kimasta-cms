<?php

declare(strict_types=1);

/*
 * Migration runner CLI.
 *
 *   php bin/migrate.php            run every pending migration
 *   php bin/migrate.php status     list pending/applied migrations
 *   php bin/migrate.php rollback   roll back the most recent batch
 *
 * `migrate` runs in rounds of five and repeats until nothing is left. The runner caps each
 * call at five migrations to bound execution time on shared hosting, but a CLI that silently
 * stopped after the fifth would leave a fresh install half-migrated: the operator sees
 * "Executed 5 migration(s)", believes it is done, and then gets "table not found" from a
 * deploy that reported success. The batching belongs inside the runner; the operator should
 * not have to know about it.
 *
 * Refuses to run destructive operations unless APP_ENV is known, and locks itself so two
 * concurrent deploys cannot interleave DDL.
 */

use CMS\Core\Bootstrap\Application;
use CMS\Core\Database\ConnectionFactory;
use CMS\Core\Database\Migrations\MigrationHistory;
use CMS\Core\Database\Migrations\MigrationRepository;
use CMS\Core\Database\Migrations\MigrationRunner;
use CMS\Core\Filesystem\Filesystem;
use CMS\Core\Filesystem\FileLock;
use CMS\Shared\Contracts\ConfigInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = Application::create(dirname(__DIR__));
$app->boot();

$container = $app->container();

/** @var ConfigInterface $config */
$config = $container->get(ConfigInterface::class);

$storage = (string) $config->get('app.storage_path', '');

if ($storage === '') {
    $storage = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
}

$migrationsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';

$filesystem = new Filesystem(dirname(__DIR__));
$lock = new FileLock($storage . DIRECTORY_SEPARATOR . 'locks');

$connection = $container->get(ConnectionFactory::class)->create();
$table = (string) $config->get('database.migrations_table', 'migrations');

$history = new MigrationHistory($connection, $table);

$runner = new MigrationRunner(
    new MigrationRepository($filesystem, 'database/migrations'),
    $history,
    $connection,
    $lock,
);

$command = $argv[1] ?? 'migrate';

try {
    switch ($command) {
        case 'status':
            // The tracking table may not exist yet on a fresh database.
            $history->ensureTable();

            $pending = $runner->pending();
            $applied = $history->executed();

            fwrite(STDOUT, 'Applied: ' . count($applied) . PHP_EOL);

            foreach ($applied as $name) {
                fwrite(STDOUT, '  + ' . $name . PHP_EOL);
            }

            fwrite(STDOUT, 'Pending: ' . count($pending) . PHP_EOL);

            foreach ($pending as $name) {
                fwrite(STDOUT, '  - ' . $name . PHP_EOL);
            }

            exit(0);

        case 'rollback':
            $rolledBack = $runner->rollback(1, 5);

            fwrite(STDOUT, sprintf("Rolled back %d migration(s).\n", count($rolledBack)));

            foreach ($rolledBack as $name) {
                fwrite(STDOUT, '  - ' . $name . PHP_EOL);
            }

            exit(0);

        case 'migrate':
        default:
            // Repeat in rounds until a round finds nothing to do. A round returning zero is
            // the only reliable stop condition: a fixed count would either stop early (the
            // bug this loop exists to fix) or spin forever.
            $executed = [];
            $rounds = 0;

            do {
                $batch = $runner->migrate(5);
                $executed = array_merge($executed, $batch);
                $rounds++;
            } while ($batch !== [] && $rounds < 100);

            fwrite(STDOUT, sprintf("Executed %d migration(s).\n", count($executed)));

            foreach ($executed as $name) {
                fwrite(STDOUT, '  - ' . $name . PHP_EOL);
            }

            exit(0);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}