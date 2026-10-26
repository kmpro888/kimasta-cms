<?php

declare(strict_types=1);

namespace CMS\Shared\Contracts;

/**
 * Cache abstraction. Filesystem cache is the production default.
 *
 * FROZEN CONTRACT.
 */
interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    public function put(string $key, mixed $value, ?int $ttl = null): void;

    public function forever(string $key, mixed $value): void;

    public function forget(string $key): void;

    public function flush(): void;
}
