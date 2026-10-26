<?php

declare(strict_types=1);

namespace CMS\Core\Filesystem;

/**
 * Filesystem abstraction used by cache, logging and uploads.
 */
interface FilesystemInterface
{
    public function exists(string $path): bool;

    public function isDirectory(string $path): bool;

    public function get(string $path): ?string;

    public function put(string $path, string $contents): void;

    public function delete(string $path): bool;

    public function makeDirectory(string $path, int $mode = 0775): void;

    public function deleteDirectory(string $path): bool;

    /** @return list<string> */
    public function readLines(string $path, int $limit = 100): array;

    public function append(string $path, string $contents): void;
}
