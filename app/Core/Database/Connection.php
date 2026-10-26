<?php

declare(strict_types=1);

namespace CMS\Core\Database;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper.
 *
 * Provides prepared-statement helpers only. No query builder and no ORM:
 * repositories are expected to write explicit SQL so query counts stay
 * predictable on high-traffic pages.
 */
final class Connection
{
    private ?QueryProfiler $profiler = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function profiler(?QueryProfiler $profiler): void
    {
        $this->profiler = $profiler;
    }

    /** @param array<string|int, mixed> $bindings */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings)->fetchAll();
    }

    /** @param array<string|int, mixed> $bindings */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string|int, mixed> $bindings */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string|int, mixed> $bindings */
    public function statement(string $sql, array $bindings = []): PDOStatement
    {
        return $this->run($sql, $bindings);
    }

    /** @param array<string|int, mixed> $bindings */
    public function affect(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /** @param array<string|int, mixed> $bindings */
    public function insert(string $sql, array $bindings = []): int
    {
        $this->run($sql, $bindings);

        return (int) $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string|int, mixed> $bindings */
    private function run(string $sql, array $bindings = []): PDOStatement
    {
        $started = $this->profiler !== null ? microtime(true) : 0.0;

        $statement = $this->pdo->prepare($sql);

        foreach ($bindings as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : $key;
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                is_null($value) => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };

            $statement->bindValue($parameter, $value, $type);
        }

        $statement->execute();

        if ($this->profiler !== null) {
            $this->profiler->record($sql, microtime(true) - $started);
        }

        return $statement;
    }
}
