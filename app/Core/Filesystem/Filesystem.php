<?php

declare(strict_types=1);

namespace CMS\Core\Filesystem;

use RuntimeException;

/**
 * Local filesystem implementation with atomic writes.
 *
 * A write goes to a temporary file in the same directory and is then
 * renamed, so readers never observe a partially written file.
 */
final class Filesystem implements FilesystemInterface
{
    public function __construct(private readonly string $root)
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $relative): string
    {
        $relative = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);

        return $this->root . DIRECTORY_SEPARATOR . $relative;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->path($path));
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($this->path($path));
    }

    public function get(string $path): ?string
    {
        $absolute = $this->path($path);

        if (!is_file($absolute)) {
            return null;
        }

        $contents = @file_get_contents($absolute);

        return $contents === false ? null : $contents;
    }

    public function put(string $path, string $contents): void
    {
        $absolute = $this->path($path);
        $directory = dirname($absolute);

        $this->makeDirectory($directory);

        $temp = @tempnam($directory, '.cms_tmp_');

        if ($temp === false) {
            throw new RuntimeException('Unable to create temporary file for atomic write.');
        }

        $written = @file_put_contents($temp, $contents, LOCK_EX);

        if ($written === false || $written !== strlen($contents)) {
            @unlink($temp);

            throw new RuntimeException('Unable to write file contents.');
        }

        @chmod($temp, 0664);

        if (!@rename($temp, $absolute)) {
            @unlink($temp);

            throw new RuntimeException('Unable to atomically replace target file.');
        }
    }

    public function append(string $path, string $contents): void
    {
        $absolute = $this->path($path);
        $this->makeDirectory(dirname($absolute));

        if (@file_put_contents($absolute, $contents, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to append to file.');
        }
    }

    public function delete(string $path): bool
    {
        $absolute = $this->path($path);

        if (!is_file($absolute)) {
            return true;
        }

        return @unlink($absolute);
    }

    public function makeDirectory(string $path, int $mode = 0775): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create directory.');
        }
    }

    public function deleteDirectory(string $path): bool
    {
        $absolute = $this->path($path);

        if (!is_dir($absolute)) {
            return true;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        return @rmdir($absolute);
    }

    /** @return list<string> */
    public function readLines(string $path, int $limit = 100): array
    {
        $absolute = $this->path($path);

        if (!is_file($absolute)) {
            return [];
        }

        $lines = @file($absolute, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        return array_slice($lines, 0, max(0, $limit));
    }
}
