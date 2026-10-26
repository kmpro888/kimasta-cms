<?php

declare(strict_types=1);

namespace Tests\Support;

use CMS\Core\Config\Env;
use CMS\Core\Database\Connection;
use PDO;

/**
 * Base test case for tests that need a real database.
 *
 * Connects to a testing database only. A hard safety guard aborts when the
 * configured database name is not a testing database, so a misconfigured
 * environment can never truncate production data.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static ?Connection $sharedConnection = null;

    protected Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = self::connection();
    }

    protected static function connection(): Connection
    {
        if (self::$sharedConnection instanceof Connection) {
            return self::$sharedConnection;
        }

        $env = new Env();
        $env->load(dirname(__DIR__, 2) . '/.env');

        // phpunit.xml supplies testing credentials as process env vars, and
        // those must win over a developer's local .env so a test run can
        // never be redirected at the development database.
        $database = (string) (getenv('DB_DATABASE') ?: ($env->get('DB_DATABASE') ?? ''));

        if (!self::isTestingDatabase($database)) {
            self::fail(
                'Refusing to run database tests against "' . $database . '": not a testing database.'
            );
        }

        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                (string) (getenv('DB_HOST') ?: ($env->get('DB_HOST') ?? '127.0.0.1')),
                (string) (getenv('DB_PORT') ?: ($env->get('DB_PORT') ?? '3306')),
                $database,
            ),
            (string) (getenv('DB_USERNAME') ?: ($env->get('DB_USERNAME') ?? '')),
            (string) (getenv('DB_PASSWORD') ?: ($env->get('DB_PASSWORD') ?? '')),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );

        self::$sharedConnection = new Connection($pdo);

        return self::$sharedConnection;
    }

    /**
     * Destructive resets are only ever allowed on an explicitly named
     * testing database.
     */
    protected static function isTestingDatabase(string $database): bool
    {
        if ($database === '') {
            return false;
        }

        return str_ends_with($database, '_test') || str_ends_with($database, '_testing');
    }
}