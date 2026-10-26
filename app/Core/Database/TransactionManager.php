<?php

declare(strict_types=1);

namespace CMS\Core\Database;

use CMS\Core\Database\Exceptions\NestedTransactionException;
use CMS\Shared\Contracts\TransactionManagerInterface;
use Throwable;

/**
 * Transaction boundary manager.
 *
 * Nested transactions are explicitly unsupported in the MVP rather than
 * faked with savepoints: a silent savepoint would make failure semantics
 * ambiguous for module authors. Attempting to nest throws immediately.
 */
final class TransactionManager implements TransactionManagerInterface
{
    private int $level = 0;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function run(callable $callback): mixed
    {
        $this->begin();

        try {
            $result = $callback($this);
        } catch (Throwable $exception) {
            $this->rollback();

            throw $exception;
        }

        $this->commit();

        return $result;
    }

    public function begin(): void
    {
        if ($this->level > 0) {
            throw new NestedTransactionException();
        }

        $this->connection->beginTransaction();
        $this->level = 1;
    }

    public function commit(): void
    {
        if ($this->level === 0) {
            return;
        }

        $this->connection->commit();
        $this->level = 0;
    }

    public function rollback(): void
    {
        if ($this->level === 0) {
            return;
        }

        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }

        $this->level = 0;
    }

    public function level(): int
    {
        return $this->level;
    }
}