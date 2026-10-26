<?php

declare(strict_types=1);

namespace CMS\Modules\Plugin\Service;

use CMS\Modules\Plugin\Contracts\HookRegistryInterface;

/**
 * Actions and filters.
 *
 * Callbacks are stored per owner (the plugin name) so deactivating one plugin removes
 * exactly its hooks and nothing else. Without that, deactivating a plugin would either
 * leave its callbacks running or require clearing every hook in the system.
 *
 * Higher priority runs first, matching the domain event dispatcher — one ordering rule
 * across the platform is easier to reason about than two.
 */
final class HookRegistry implements HookRegistryInterface
{
    /** @var array<string, list<array{callback: callable, priority: int, owner: string}>> */
    private array $actions = [];

    /** @var array<string, list<array{callback: callable, priority: int, owner: string}>> */
    private array $filters = [];

    private string $owner = 'core';

    /**
     * Everything registered from now until `endOwner()` belongs to this owner.
     */
    public function beginOwner(string $owner): void
    {
        $this->owner = $owner;
    }

    public function endOwner(): void
    {
        $this->owner = 'core';
    }

    public function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        $this->actions[$hook][] = ['callback' => $callback, 'priority' => $priority, 'owner' => $this->owner];
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        $this->filters[$hook][] = ['callback' => $callback, 'priority' => $priority, 'owner' => $this->owner];
    }

    public function doAction(string $hook, array $args = []): void
    {
        foreach ($this->sorted($this->actions[$hook] ?? []) as $entry) {
            ($entry['callback'])(...$args);
        }
    }

    public function applyFilters(string $hook, mixed $value, array $args = []): mixed
    {
        foreach ($this->sorted($this->filters[$hook] ?? []) as $entry) {
            $value = ($entry['callback'])($value, ...$args);
        }

        return $value;
    }

    public function removeAll(string $hook): void
    {
        unset($this->actions[$hook], $this->filters[$hook]);
    }

    public function removeOwner(string $owner): void
    {
        foreach (['actions', 'filters'] as $bucket) {
            foreach (array_keys($this->{$bucket}) as $hook) {
                $this->{$bucket}[$hook] = array_values(array_filter(
                    $this->{$bucket}[$hook],
                    static fn (array $entry): bool => $entry['owner'] !== $owner
                ));

                if ($this->{$bucket}[$hook] === []) {
                    unset($this->{$bucket}[$hook]);
                }
            }
        }
    }

    public function actions(): array
    {
        return array_keys($this->actions);
    }

    public function filters(): array
    {
        return array_keys($this->filters);
    }

    /**
     * @param list<array{callback: callable, priority: int, owner: string}> $entries
     *
     * @return list<array{callback: callable, priority: int, owner: string}>
     */
    private function sorted(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        // Stable within equal priority: registration order decides, so a plugin cannot
        // reorder another plugin's callbacks by choosing the same priority.
        usort($entries, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return $entries;
    }
}
