<?php

declare(strict_types=1);

namespace CMS\Core\Support;

/**
 * Deployment preflight checks.
 *
 * Every check here exists because it is something that actually broke, or would have
 * broken silently, while this platform was being built and deployed:
 *
 *  - the database defaulting to MyISAM, which accepts the migrations and then ignores
 *    foreign keys and transactions;
 *  - `APP_DEBUG` left on in production, which prints stack traces to visitors;
 *  - a non-writable storage directory, which turns a cache miss into a 500;
 *  - unapplied migrations, which produce "table not found" from a working deploy;
 *  - the uploads directory without its execution guard, which turns a media upload into
 *    remote code execution;
 *  - PHP extensions that media or the database requires.
 *
 * The checks are pure functions of their arguments so they can be tested without a
 * server. `bin/doctor.php` gathers the real values and prints the result.
 */
final class Doctor
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const FAILURE = 'failure';

    /** Extensions without which the platform cannot run at all. */
    public const REQUIRED_EXTENSIONS = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'fileinfo'];

    /** Extensions needed for image handling; a degraded path exists without them. */
    public const OPTIONAL_EXTENSIONS = ['gd', 'exif'];

    public const MINIMUM_PHP = '8.2.0';

    /**
     * @return array{level: string, message: string}
     */
    public static function checkPhpVersion(string $version, string $minimum = self::MINIMUM_PHP): array
    {
        if (version_compare($version, $minimum, '<')) {
            return [
                'level' => self::FAILURE,
                'message' => 'PHP ' . $version . ' is below the required ' . $minimum . '.',
            ];
        }

        return ['level' => self::OK, 'message' => 'PHP ' . $version . '.'];
    }

    /**
     * @param list<string> $loaded
     * @param list<string> $required
     *
     * @return array{level: string, message: string}
     */
    public static function checkExtensions(array $loaded, array $required = self::REQUIRED_EXTENSIONS): array
    {
        // Extension names are not consistently cased by PHP: `get_loaded_extensions()`
        // reports `PDO` while `pdo_mysql` is lower case. Comparing case-sensitively made
        // this check report a missing PDO on a server where PDO was loaded.
        $missing = array_values(array_diff(
            array_map('strtolower', $required),
            array_map('strtolower', $loaded),
        ));

        if ($missing !== []) {
            return [
                'level' => self::FAILURE,
                'message' => 'Missing required extension(s): ' . implode(', ', $missing) . '.',
            ];
        }

        return ['level' => self::OK, 'message' => 'All required extensions loaded.'];
    }

    /**
     * @param list<string> $loaded
     * @param list<string> $optional
     *
     * @return array{level: string, message: string}
     */
    public static function checkOptionalExtensions(
        array $loaded,
        array $optional = self::OPTIONAL_EXTENSIONS,
    ): array {
        $missing = array_values(array_diff(
            array_map('strtolower', $optional),
            array_map('strtolower', $loaded),
        ));

        if ($missing !== []) {
            // Multiple image formats or EXIF metadata will be unavailable, but pages still
            // render, so this is a warning rather than a failure.
            return [
                'level' => self::WARNING,
                'message' => 'Optional extension(s) missing: ' . implode(', ', $missing)
                    . '. Image handling will be limited.',
            ];
        }

        return ['level' => self::OK, 'message' => 'Optional image extensions loaded.'];
    }

    /**
     * @return array{level: string, message: string}
     */
    public static function checkDebug(string $appEnv, bool $appDebug): array
    {
        $production = strtolower($appEnv) === 'production';

        if ($production && $appDebug) {
            return [
                'level' => self::FAILURE,
                'message' => 'APP_DEBUG is true while APP_ENV=production. This exposes stack traces, '
                    . 'SQL and filesystem paths to visitors. Set APP_DEBUG=false.',
            ];
        }

        if (!$production && $appDebug) {
            return [
                'level' => self::OK,
                'message' => 'Debug is on for a non-production environment (' . $appEnv . ').',
            ];
        }

        return ['level' => self::OK, 'message' => 'Debug is off.'];
    }

    /**
     * @return array{level: string, message: string}
     */
    public static function checkAppKey(string $appKey): array
    {
        if (trim($appKey) === '') {
            return [
                'level' => self::WARNING,
                'message' => 'APP_KEY is empty. Generate one: php -r "echo bin2hex(random_bytes(32));"',
            ];
        }

        if (strlen($appKey) < 32) {
            return [
                'level' => self::WARNING,
                'message' => 'APP_KEY looks short. A 32-byte hex value is the expected form.',
            ];
        }

        return ['level' => self::OK, 'message' => 'APP_KEY is set.'];
    }

    /**
     * InnoDB is required rather than preferred: the schema depends on foreign keys and
     * transactions, and a MyISAM default accepts the migrations and then ignores both.
     *
     * @param list<string> $nonInnoDbTables
     *
     * @return array{level: string, message: string}
     */
    public static function checkStorageEngine(string $defaultEngine, array $nonInnoDbTables = []): array
    {
        if ($nonInnoDbTables !== []) {
            return [
                'level' => self::FAILURE,
                'message' => 'These tables are not InnoDB: ' . implode(', ', $nonInnoDbTables)
                    . '. Foreign keys and transactions are not enforced on them.',
            ];
        }

        if (strcasecmp($defaultEngine, 'InnoDB') !== 0) {
            return [
                'level' => self::WARNING,
                'message' => 'The server default engine is ' . $defaultEngine
                    . '. Existing tables are fine, but new ones will inherit it.',
            ];
        }

        return ['level' => self::OK, 'message' => 'Storage engine is InnoDB.'];
    }

    /**
     * @return array{level: string, message: string}
     */
    public static function checkMigrations(int $pending): array
    {
        if ($pending > 0) {
            return [
                'level' => self::FAILURE,
                'message' => $pending . ' migration(s) pending. Run: php bin/migrate.php',
            ];
        }

        return ['level' => self::OK, 'message' => 'All migrations applied.'];
    }

    /**
     * @param array<string, bool> $paths label => is_writable
     *
     * @return array{level: string, message: string}
     */
    public static function checkPaths(array $paths): array
    {
        $bad = [];

        foreach ($paths as $label => $writable) {
            if (!$writable) {
                $bad[] = $label;
            }
        }

        if ($bad !== []) {
            // A non-writable storage directory turns a cache miss into a 500, which is the
            // least diagnosable failure this platform can produce.
            return [
                'level' => self::FAILURE,
                'message' => 'Not writable: ' . implode(', ', $bad) . '.',
            ];
        }

        return ['level' => self::OK, 'message' => 'Writable directories are writable.'];
    }

    /**
     * The uploads directory must refuse to execute scripts, otherwise an uploaded file is
     * remote code execution.
     *
     * @return array{level: string, message: string}
     */
    public static function checkUploadsGuard(bool $guardPresent): array
    {
        if (!$guardPresent) {
            return [
                'level' => self::FAILURE,
                'message' => 'public/uploads/.htaccess is missing. Uploaded files could be executed as scripts.',
            ];
        }

        return ['level' => self::OK, 'message' => 'Uploads directory denies script execution.'];
    }

    /**
     * @return array{level: string, message: string}
     */
    public static function checkSitemap(bool $artifactPresent): array
    {
        if (!$artifactPresent) {
            return [
                'level' => self::WARNING,
                'message' => 'No sitemap artifact yet. /sitemap.xml returns 404 until cron runs once.',
            ];
        }

        return ['level' => self::OK, 'message' => 'Sitemap artifact present.'];
    }

    /**
     * @param array<string, array{level: string, message: string}> $results
     *
     * @return array{ok: int, warning: int, failure: int}
     */
    public static function summarise(array $results): array
    {
        $counts = [self::OK => 0, self::WARNING => 0, self::FAILURE => 0];

        foreach ($results as $result) {
            $counts[$result['level']] = ($counts[$result['level']] ?? 0) + 1;
        }

        return $counts;
    }
}
