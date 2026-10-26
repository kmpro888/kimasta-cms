<?php

declare(strict_types=1);

namespace CMS\Shared\Contracts;

/**
 * Transaction boundary helper.
 *
 * FROZEN CONTRACT.
 *
 * Nested transactions are not supported in MVP and must throw.
 */
interface TransactionManagerInterface
{
    public function run(callable $callback): mixed;

    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    public function level(): int;
}
