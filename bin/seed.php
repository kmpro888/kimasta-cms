<?php

declare(strict_types=1);

/*
 * Seed runner CLI.
 *
 *   php bin/seed.php                     list available seeders
 *   php bin/seed.php all                 run every seeder in order
 *   php bin/seed.php <Name>              run one seeder by class name
 *   php bin/seed.php --reseed-roles      re-create the role/capability matrix for every site
 *
 * `--reseed-roles` exists because adding a capability to the catalogue does not create it
 * in an existing database: the site then answers `403 Missing capability: ...` and it looks
 * like a permissions bug. Re-running the seeder is the upgrade step, and `seedDefaults()` is
 * idempotent, so running it on every upgrade is safe.
 *
 * Production seeding must be explicit: when APP_ENV is "production" the runner refuses to
 * do anything unless --force is also passed.
 */

use CMS\Modules\Identity\Contracts\RoleServiceInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;
use CMS\Shared\Contracts\ConfigInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

// The real composition root: module seeders need module providers, and this file used to
// build a container with Core only -- the same drift that once broke bin/cron.php.
$app = require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
$app->boot();

$container = $app->container();

/** @var ConfigInterface $config */
$config = $container->get(ConfigInterface::class);

$env = (string) $config->get('app.env', 'production');
$force = in_array('--force', $argv, true);
$reseedRoles = in_array('--reseed-roles', $argv, true);

$arguments = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $value): bool => !str_starts_with($value, '--'),
));

if ($env === 'production' && !$force) {
    fwrite(STDERR, 'Refusing to seed in production without --force.' . PHP_EOL);
    exit(1);
}

if ($reseedRoles) {
    /** @var SiteServiceInterface $sites */
    $sites = $container->get(SiteServiceInterface::class);

    /** @var RoleServiceInterface $roles */
    $roles = $container->get(RoleServiceInterface::class);

    $count = 0;

    foreach ($sites->all() as $site) {
        $roles->seedDefaults($site->id());
        $count++;

        fwrite(STDOUT, sprintf("Re-seeded roles and capabilities for site %d (%s).\n", $site->id(), $site->slug()));
    }

    if ($count === 0) {
        fwrite(STDERR, 'No sites found; nothing to re-seed.' . PHP_EOL);
        exit(1);
    }

    fwrite(STDOUT, sprintf("Done: %d site(s).\n", $count));
    exit(0);
}

$seedsDir = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeds';
$namespace = 'Database\\Seeds\\';

$files = [];

if (is_dir($seedsDir)) {
    foreach (scandir($seedsDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || !str_ends_with($entry, 'Seeder.php')) {
            continue;
        }

        $class = $namespace . basename($entry, '.php');

        if (class_exists($class)) {
            $files[$class] = $class;
        }
    }
}

ksort($files);

$requested = $arguments[0] ?? 'list';

if ($requested === 'list') {
    fwrite(STDOUT, 'Available seeders: ' . count($files) . PHP_EOL);

    foreach (array_keys($files) as $class) {
        fwrite(STDOUT, '  - ' . $class . PHP_EOL);
    }

    if ($files === []) {
        fwrite(STDOUT, 'No seeders registered.' . PHP_EOL);
    }

    fwrite(STDOUT, 'Use --reseed-roles to recreate the role/capability matrix for every site.' . PHP_EOL);

    exit(0);
}

$selected = $requested === 'all' ? $files : array_intersect_key($files, [$namespace . $requested => true]);

if ($selected === []) {
    fwrite(STDERR, 'Seeder not found: ' . $requested . PHP_EOL);
    exit(1);
}

foreach ($selected as $class) {
    /** @var object $seeder */
    $seeder = new $class($container);

    if (!method_exists($seeder, 'run')) {
        fwrite(STDERR, 'Seeder has no run() method: ' . $class . PHP_EOL);
        exit(1);
    }

    $seeder->run();

    fwrite(STDOUT, 'Seeded: ' . $class . PHP_EOL);
}

exit(0);
