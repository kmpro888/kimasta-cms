<?php

declare(strict_types=1);

/**
 * Deployment preflight.
 *
 *   php bin/doctor.php
 *
 * Exit code 0 when nothing failed, 1 when at least one check failed. Warnings do not fail
 * the run: they describe something that degrades the site rather than breaks it.
 *
 * This exists because every check in it is a failure that actually happened while building
 * and deploying this platform, and each one was silent at the time.
 */

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Support\Doctor;
use CMS\Shared\Contracts\ConfigInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

$results = [];
$results['php'] = Doctor::checkPhpVersion(PHP_VERSION);
$results['extensions'] = Doctor::checkExtensions(get_loaded_extensions());
$results['image extensions'] = Doctor::checkOptionalExtensions(get_loaded_extensions());

try {
    $app = require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    $app->boot();
    $container = $app->container();

    /** @var ConfigInterface $config */
    $config = $container->get(ConfigInterface::class);

    $results['debug guard'] = Doctor::checkDebug(
        (string) $config->get('app.env', 'production'),
        (bool) $config->get('app.debug', false),
    );
    $results['app key'] = Doctor::checkAppKey((string) $config->get('app.key', ''));

    /** @var Connection $db */
    $db = $container->get(Connection::class);

    $engineRow = $db->selectOne("SHOW VARIABLES LIKE 'default_storage_engine'");
    $defaultEngine = is_array($engineRow) ? (string) ($engineRow['Value'] ?? '') : '';

    $nonInnoDb = [];
    $rows = $db->select(
        'SELECT table_name AS t, engine AS e FROM information_schema.tables WHERE table_schema = DATABASE()'
    );

    foreach ($rows as $row) {
        if (strcasecmp((string) $row['e'], 'InnoDB') !== 0) {
            $nonInnoDb[] = (string) $row['t'];
        }
    }

    $results['storage engine'] = Doctor::checkStorageEngine($defaultEngine, $nonInnoDb);

    $applied = (int) ($db->selectOne('SELECT COUNT(*) AS total FROM migrations')['total'] ?? 0);
    $files = glob($root . '/database/migrations/*.php') ?: [];
    $results['migrations'] = Doctor::checkMigrations(max(0, count($files) - $applied));

    $storage = (string) $config->get('app.storage_path', $root . '/storage');

    if ($storage === '') {
        $storage = $root . '/storage';
    }

    $results['writable paths'] = Doctor::checkPaths([
        'storage' => is_dir($storage) ? is_writable($storage) : is_writable(dirname($storage)),
        'storage/cache' => is_dir($storage . '/cache')
            ? is_writable($storage . '/cache')
            : is_writable($storage),
        'public/uploads' => is_dir($root . '/public/uploads')
            ? is_writable($root . '/public/uploads')
            : is_writable($root . '/public'),
    ]);

    $results['uploads guard'] = Doctor::checkUploadsGuard(is_file($root . '/public/uploads/.htaccess'));

    $siteRow = $db->selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
    $siteId = $siteRow === null ? 0 : (int) $siteRow['id'];

    $sitemap = $siteId > 0
        ? is_file($storage . '/cache/system/sitemaps/sitemap-' . $siteId . '.xml')
        : false;

    $results['sitemap'] = Doctor::checkSitemap($sitemap);
} catch (Throwable $exception) {
    $results['bootstrap'] = [
        'level' => Doctor::FAILURE,
        'message' => 'The application could not boot: ' . $exception->getMessage(),
    ];
}

$counts = Doctor::summarise($results);

foreach ($results as $label => $result) {
    $marker = match ($result['level']) {
        Doctor::FAILURE => 'FAIL',
        Doctor::WARNING => 'WARN',
        default => ' OK ',
    };

    fwrite($result['level'] === Doctor::FAILURE ? STDERR : STDOUT, sprintf(
        "[%s] %-18s %s\n",
        $marker,
        $label,
        $result['message'],
    ));
}

fwrite(STDOUT, sprintf(
    "\n%d ok, %d warning(s), %d failure(s)\n",
    $counts[Doctor::OK],
    $counts[Doctor::WARNING],
    $counts[Doctor::FAILURE],
));

fwrite(STDOUT, "Not checked here: cron is scheduled, the vhost DocumentRoot points at public/, and DNS resolves.\n");

exit($counts[Doctor::FAILURE] > 0 ? 1 : 0);
