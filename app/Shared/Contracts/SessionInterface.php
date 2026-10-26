<?php

declare(strict_types=1);

namespace CMS\Shared\Contracts;

/**
 * Session storage abstraction.
 *
 * FROZEN CONTRACT — implementation belongs to Agent 03.
 */
interface SessionInterface
{
    public function start(): void;

    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value): void;

    public function forget(string $key): void;

    public function regenerate(): void;

    public function destroy(): void;

    public function all(): array;
}
