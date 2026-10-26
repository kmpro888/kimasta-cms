<?php

declare(strict_types=1);

namespace CMS\Core\Container;

use Closure;
use CMS\Core\Container\Exceptions\BindingNotFoundException;

/**
 * Lightweight explicit service container.
 *
 * No reflection autowiring by design: registration is explicit so that
 * bootstrap cost stays predictable and dependency graphs stay readable.
 */
final class Container
{
    /** @var array<string, array{concrete: callable, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, string> */
    private array $aliases = [];

    public function bind(string $abstract, callable $concrete, bool $shared = false): void
    {
        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared' => $shared,
        ];

        unset($this->instances[$abstract]);
    }

    public function singleton(string $abstract, callable $concrete): void
    {
        $this->bind($abstract, $concrete, true);
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
        $this->bindings[$abstract] = [
            'concrete' => static fn (Container $c): mixed => $instance,
            'shared' => true,
        ];
    }

    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    public function has(string $abstract): bool
    {
        $abstract = $this->resolveAlias($abstract);

        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    public function get(string $abstract): mixed
    {
        $abstract = $this->resolveAlias($abstract);

        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (!isset($this->bindings[$abstract])) {
            throw new BindingNotFoundException($abstract);
        }

        $binding = $this->bindings[$abstract];
        $concrete = $binding['concrete'];

        $resolved = $concrete instanceof Closure
            ? $concrete($this)
            : $concrete($this);

        if ($binding['shared']) {
            $this->instances[$abstract] = $resolved;
        }

        return $resolved;
    }

    public function forget(string $abstract): void
    {
        $abstract = $this->resolveAlias($abstract);

        unset($this->bindings[$abstract], $this->instances[$abstract]);
    }

    /** @return array<string, array{concrete: callable, shared: bool}> */
    public function bindings(): array
    {
        return $this->bindings;
    }

    private function resolveAlias(string $abstract): string
    {
        $seen = [];

        while (isset($this->aliases[$abstract])) {
            if (isset($seen[$abstract])) {
                throw new \RuntimeException('Circular container alias detected for: ' . $abstract);
            }

            $seen[$abstract] = true;
            $abstract = $this->aliases[$abstract];
        }

        return $abstract;
    }
}
