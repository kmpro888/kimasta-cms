<?php

declare(strict_types=1);

namespace CMS\Core\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Lazily builds a single PDO connection from configuration.
 *
 * The PDO handle is created on first use so that a fully cached public
 * request never opens a database connection at all.
 */
final class ConnectionFactory
{
    private ?Connection $connection = null;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function connection(): Connection
    {
        return $this->connection ??= $this->create();
    }

    public function create(): Connection
    {
        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        $port = (int) ($this->config['port'] ?? 3306);
        $database = (string) ($this->config['database'] ?? '');
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');
        $charset = (string) ($this->config['charset'] ?? 'utf8mb4');

        if ($database === '') {
            throw new RuntimeException('Database name is not configured.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);

        try {
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $exception) {
            // Never surface credentials or DSN details to the caller.
            throw new RuntimeException('Unable to establish a database connection.', 0, $exception);
        }

        return new Connection($pdo);
    }
}
