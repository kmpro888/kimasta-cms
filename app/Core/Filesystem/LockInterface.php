<?php

declare(strict_types=1);

namespace CMS\Core\Filesystem;

/**
 * Named advisory lock used to serialize migrations and cron runs.
 */
interface LockInterface
{
    public function acquire(string $name, int $timeoutSeconds = 0): bool;

    public function release(string $name): void;

    public function isLocked(string $name): bool;
}
