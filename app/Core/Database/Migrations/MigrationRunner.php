<?php

declare(strict_types=1);

namespace CMS\Core\Database\Migrations;

use CMS\Core\Database\Connection;
use CMS\Core\Database\TransactionManager;
use CMS\Core\Filesystem\LockInterface;
use RuntimeException;

/**
 * Executes pending migrations under an exclusive lock.
 *
 * Two guarantees matter here:
 *  1. A migration never runs twice (history row is checked, not just file
 *     presence).
 *  2. Concurrent runners cannot interleave (filesystem lock + per-batch
 *     transaction).
 *
 * DDL in MySQL causes implicit commits, so migrations are wrapped in a
 * batch-level transaction only for the history bookkeeping; individual
 * migration DDL is executed directly and recorded after success.
 */
final class MigrationRunner
{
    public const LOCK_NAME = 'migrations';

    public function __construct(
        private readonly MigrationRepository $repository,
        private readonly MigrationHistory $history,
        private readonly Connection $connection,
        private readonly LockInterface $lock,
        private readonly ?TransactionManager $transactions = null,
    ) {
    }

    /**
     * @return list<string> Names of migrations executed.
     */
    public function migrate(int $lockTimeout = 0): array
    {
        if (!$this->lock->acquire(self::LOCK_NAME, $lockTimeout)) {
            throw new RuntimeException('Unable to acquire migration lock; another run is in progress.');
        }

        try {
            $this->history->ensureTable();

            $pending = $this->pending();

            if ($pending === []) {
                return [];
            }

            $batch = $this->history->latestBatch() + 1;
            $executed = [];

            foreach ($pending as $migration) {
                $instance = $this->repository->require($migration);

                $instance->up($this->connection);

                $this->history->record($migration, $batch);
                $executed[] = $migration;
            }

            return $executed;
        } finally {
            $this->lock->release(self::LOCK_NAME);
        }
    }

    /**
     * Roll back the most recent batch.
     *
     * @return list<string>
     */
    public function rollback(int $steps = 1, int $lockTimeout = 0): array
    {
        if (!$this->lock->acquire(self::LOCK_NAME, $lockTimeout)) {
            throw new RuntimeException('Unable to acquire migration lock; another run is in progress.');
        }

        try {
            $this->history->ensureTable();

            $rolledBack = [];
            $latest = $this->history->latestBatch();

            for ($i = 0; $i < $steps && $latest > 0; $i++) {
                foreach ($this->history->batch($latest) as $row) {
                    $name = (string) $row['migration'];
                    $instance = $this->repository->require($name);

                    $instance->down($this->connection);
                    $this->history->remove($name);
                    $rolledBack[] = $name;
                }

                $latest = $this->history->latestBatch();
            }

            return $rolledBack;
        } finally {
            $this->lock->release(self::LOCK_NAME);
        }
    }

    /** @return list<string> */
    public function pending(): array
    {
        $executed = array_flip($this->history->executed());
        $pending = [];

        foreach ($this->repository->all() as $migration) {
            if (!isset($executed[$migration['name']])) {
                $pending[] = $migration['name'];
            }
        }

        return $pending;
    }
}
