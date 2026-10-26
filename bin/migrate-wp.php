<?php

declare(strict_types=1);

/**
 * WordPress migration runner.
 *
 *   php bin/migrate-wp.php analyze <site-slug>
 *   php bin/migrate-wp.php plan    <site-slug>
 *   php bin/migrate-wp.php run     <site-slug> [batchSize]
 *   php bin/migrate-wp.php batch   <site-slug> <type> [limit] [after]
 *   php bin/migrate-wp.php report  <site-slug>
 *   php bin/migrate-wp.php logs    <site-slug> [level]
 *
 * The CLI is the normal runner because a full import will exceed any shared-host request
 * timeout. Every step is idempotent, so re-running `run` after an interruption resumes
 * rather than duplicating.
 */

declare(strict_types=1);

use CMS\Modules\Migration\Contracts\MigrationServiceInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

// Same composition root as HTTP and cron: one wiring, no drift.
$app = require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
$app->boot();

$container = $app->container();

$command = $argv[1] ?? 'analyze';
$slug = $argv[2] ?? '';

if ($slug === '') {
    fwrite(STDERR, "Usage: php bin/migrate-wp.php <analyze|plan|run|batch|report|logs> <site-slug> [...]\n");
    exit(2);
}

try {
    /** @var SiteServiceInterface $sites */
    $sites = $container->get(SiteServiceInterface::class);
    $site = $sites->findBySlug($slug);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Unknown site "' . $slug . '": ' . $exception->getMessage() . PHP_EOL);
    exit(2);
}

$siteId = $site->id();

/** @var MigrationServiceInterface $migration */
$migration = $container->get(MigrationServiceInterface::class);

$exitCode = 0;

try {
    switch ($command) {
        case 'analyze':
            $report = $migration->analyze($siteId);
            $available = $migration->report($siteId)['source']['available'];

            if (!$available) {
                fwrite(STDERR, "No WordPress source found (prefix \"wp_\"). Configure migration.source_dsn/prefix.\n");
            }

            fwrite(STDOUT, "Source analysis for " . $slug . ":\n");

            foreach ($report as $type => $row) {
                fwrite(STDOUT, sprintf(
                    "  %-8s source=%-6d imported=%-6d pending=%d\n",
                    $type,
                    $row['source_total'],
                    $row['imported'],
                    $row['pending']
                ));
            }

            break;

        case 'plan':
            $report = $migration->plan($siteId);
            fwrite(STDOUT, "Plan for " . $slug . ":\n");

            foreach ($report as $type => $row) {
                fwrite(STDOUT, sprintf("  %-8s pending=%d\n", $type, $row['pending']));
            }

            break;

        case 'run':
            $batchSize = isset($argv[3]) ? max(1, (int) $argv[3]) : 50;
            fwrite(STDOUT, sprintf("Running full migration in batches of %d...\n", $batchSize));

            $summary = $migration->runAll(
                $siteId,
                $batchSize,
                static function (string $type, array $result): void {
                    fwrite(STDOUT, sprintf(
                        "  %-8s imported=%-4d skipped=%-4d failed=%-4d\n",
                        $type,
                        $result['imported'],
                        $result['skipped'],
                        $result['failed']
                    ));
                }
            );

            fwrite(STDOUT, "Done.\n");

            foreach ($summary as $type => $row) {
                fwrite(STDOUT, sprintf(
                    "  %-8s imported=%-4d skipped=%-4d failed=%-4d\n",
                    $type,
                    $row['imported'],
                    $row['skipped'],
                    $row['failed']
                ));
            }

            break;

        case 'batch':
            $type = $argv[3] ?? '';
            $limit = isset($argv[4]) ? (int) $argv[4] : 50;
            $after = isset($argv[5]) ? (int) $argv[5] : 0;

            if (!in_array($type, $migration->types(), true)) {
                fwrite(STDERR, 'Unknown type. Allowed: ' . implode(', ', $migration->types()) . PHP_EOL);
                exit(2);
            }

            $result = $migration->runBatch($siteId, $type, $limit, $after);

            fwrite(STDOUT, sprintf(
                "%s: imported=%d skipped=%d failed=%d next_after=%d done=%s\n",
                $type,
                $result['imported'],
                $result['skipped'],
                $result['failed'],
                $result['next_after'],
                $result['done'] ? 'yes' : 'no'
            ));

            break;

        case 'report':
            $report = $migration->report($siteId);
            fwrite(STDOUT, "Migration report for " . $slug . ":\n");
            fwrite(STDOUT, '  source available: ' . ($report['source']['available'] ? 'yes' : 'no') . PHP_EOL);
            fwrite(STDOUT, '  permalink structure: ' . ($report['source']['permalink_structure'] ?: '(plain)') . PHP_EOL);
            fwrite(STDOUT, '  URLs preserved: ' . $report['urls']['preserved'] . PHP_EOL);
            fwrite(STDOUT, '  URLs redirected: ' . $report['urls']['redirected'] . PHP_EOL);

            foreach ($report['logs'] as $level => $total) {
                fwrite(STDOUT, sprintf("  log[%s]: %d\n", $level, $total));
            }

            break;

        case 'logs':
            $level = $argv[3] ?? 'warning';
            $rows = $migration->logs($siteId, $level, 200);

            foreach ($rows as $row) {
                fwrite(STDOUT, sprintf(
                    "[%s] %s#%d %s: %s\n",
                    $row['level'],
                    $row['source_type'],
                    $row['source_id'],
                    $row['field'] ?? '-',
                    $row['reason']
                ));
            }

            if ($rows === []) {
                fwrite(STDOUT, "No log entries at level " . $level . ".\n");
            }

            break;

        default:
            fwrite(STDERR, 'Unknown command "' . $command . '".' . PHP_EOL);
            $exitCode = 2;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
}

exit($exitCode);
