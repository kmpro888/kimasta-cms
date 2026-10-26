<?php

declare(strict_types=1);

namespace CMS\Core\Events;

/**
 * Stores listeners per event name, ordered by priority then registration.
 *
 * Higher priority numbers run first (so critical listeners can be placed
 * ahead of logging listeners). Equal priorities keep insertion order so
 * behaviour is deterministic across PHP versions.
 */
final class ListenerRegistry
{
    /** @var array<string, list<array{listener: callable, priority: int, critical: bool, sequence: int}>> */
    private array $listeners = [];

    private int $sequence = 0;

    public function add(
        string $event,
        callable $listener,
        int $priority = 10,
        bool $critical = false,
    ): void {
        $this->listeners[$event][] = [
            'listener' => $listener,
            'priority' => $priority,
            'critical' => $critical,
            'sequence' => $this->sequence++,
        ];
    }

    /** @return list<array{listener: callable, priority: int, critical: bool, sequence: int}> */
    public function for(string $event): array
    {
        if (!isset($this->listeners[$event])) {
            return [];
        }

        $listeners = $this->listeners[$event];

        usort($listeners, static function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority']
                ?: $a['sequence'] <=> $b['sequence'];
        });

        return $listeners;
    }

    public function has(string $event): bool
    {
        return isset($this->listeners[$event]) && $this->listeners[$event] !== [];
    }

    /** @return list<string> */
    public function eventNames(): array
    {
        return array_keys($this->listeners);
    }

    public function forget(string $event): void
    {
        unset($this->listeners[$event]);
    }

    public function clear(): void
    {
        $this->listeners = [];
        $this->sequence = 0;
    }
}
