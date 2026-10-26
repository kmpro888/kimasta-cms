<?php

declare(strict_types=1);

namespace CMS\Core\Filesystem;

use RuntimeException;

/**
 * Filesystem-backed advisory lock.
 *
 * Uses flock() on a dedicated lock file. flock() is released automatically
 * by the OS if the process dies, which is exactly what migrations and cron
 * need: a crashed run must never leave a permanent lock behind.
 */
final class FileLock implements LockInterface
{
    /** @var array<string, resource> */
    private array $handles = [];

    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory)) {
            if (!@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
                throw new RuntimeException('Unable to create lock directory.');
            }
        }
    }

    public function acquire(string $name, int $timeoutSeconds = 0): bool
    {
        $path = $this->path($name);
        $handle = @fopen($path, 'c');

        if ($handle === false) {
            throw new RuntimeException('Unable to open lock file.');
        }

        $deadline = microtime(true) + max(0, $timeoutSeconds);

        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                $this->handles[$name] = $handle;
                ftruncate($handle, 0);
                fwrite($handle, (string) getmypid());
                fflush($handle);

                return true;
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(100_000);
        } while (true);

        @fclose($handle);

        return false;
    }

    public function release(string $name): void
    {
        if (!isset($this->handles[$name])) {
            return;
        }

        $handle = $this->handles[$name];

        @flock($handle, LOCK_UN);
        @fclose($handle);

        unset($this->handles[$name]);
    }

    public function isLocked(string $name): bool
    {
        if (isset($this->handles[$name])) {
            return true;
        }

        $path = $this->path($name);

        if (!is_file($path)) {
            return false;
        }

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            return false;
        }

        $acquired = @flock($handle, LOCK_EX | LOCK_NB);

        if ($acquired) {
            @flock($handle, LOCK_UN);
        }

        @fclose($handle);

        return !$acquired;
    }

    private function path(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?? 'lock';

        return $this->directory . DIRECTORY_SEPARATOR . $safe . '.lock';
    }

    public function __destruct()
    {
        foreach (array_keys($this->handles) as $name) {
            $this->release($name);
        }
    }
}
