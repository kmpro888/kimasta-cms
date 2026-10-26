<?php

declare(strict_types=1);

namespace Tests\Support;

use CMS\Shared\Contracts\SessionInterface;

/**
 * In-memory session for tests.
 *
 * Records how often it was regenerated and destroyed so authentication tests can
 * assert the security behaviours (rotation on login, invalidation on logout)
 * without touching real PHP session state.
 */
final class ArraySession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    public int $regenerations = 0;

    public int $destructions = 0;

    public bool $started = false;

    /** @var list<string> */
    public array $log = [];

    public function start(): void
    {
        $this->started = true;
        $this->log[] = 'start';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
        $this->log[] = 'put:' . $key;
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
        $this->log[] = 'forget:' . $key;
    }

    public function regenerate(): void
    {
        $this->regenerations++;
        $this->log[] = 'regenerate';
    }

    public function destroy(): void
    {
        $this->destructions++;
        $this->values = [];
        $this->log[] = 'destroy';
    }

    public function all(): array
    {
        return $this->values;
    }
}
