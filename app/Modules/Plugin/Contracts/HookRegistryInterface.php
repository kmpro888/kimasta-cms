<?php

declare(strict_types=1);

namespace CMS\Modules\Plugin\Contracts;

/**
 * Actions and filters — the extensibility primitive.
 *
 * Two shapes, for two different questions:
 *
 *  - **action**: "something happened, do your thing". Return value ignored.
 *  - **filter**: "here is a value, you may change it". Return value replaces it.
 *
 * Using an action where a filter is needed is a common source of silent bugs, so they
 * are separate methods rather than one with a flag.
 *
 * FROZEN CONTRACT.
 */
interface HookRegistryInterface
{
    /**
     * @param int $priority higher runs first, matching the event dispatcher
     */
    public function addAction(string $hook, callable $callback, int $priority = 10): void;

    /**
     * @param int $priority higher runs first
     */
    public function addFilter(string $hook, callable $callback, int $priority = 10): void;

    /**
     * @param array<int, mixed> $args
     */
    public function doAction(string $hook, array $args = []): void;

    /**
     * Apply every registered filter to a value, in priority order.
     */
    public function applyFilters(string $hook, mixed $value, array $args = []): mixed;

    /**
     * Remove every callback for a hook.
     *
     * Used when a plugin is deactivated: its hooks must stop firing immediately, and
     * a long-lived process (a cron run) must not keep a stale callback alive.
     */
    public function removeAll(string $hook): void;

    /**
     * Remove only the hooks owned by one plugin.
     */
    public function removeOwner(string $owner): void;

    /**
     * @return list<string>
     */
    public function actions(): array;

    /**
     * @return list<string>
     */
    public function filters(): array;
}
