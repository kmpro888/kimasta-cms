<?php

declare(strict_types=1);

namespace Tests\Support;

use CMS\Shared\Contracts\DomainEventInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;

/**
 * Event dispatcher that records what was dispatched.
 *
 * Lets a test assert that a service emitted the events other modules rely on
 * (cache invalidation, indexing) without wiring a real dispatcher and listeners.
 */
final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<DomainEventInterface> */
    private array $dispatched = [];

    /** @var array<string, list<callable>> */
    private array $listeners = [];

    public function listen(
        string $event,
        callable $listener,
        int $priority = 10,
        bool $critical = false
    ): void {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(DomainEventInterface $event): void
    {
        $this->dispatched[] = $event;

        foreach ($this->listeners[$event->name()] ?? [] as $listener) {
            $listener($event);
        }
    }

    /** @return list<DomainEventInterface> */
    public function dispatched(): array
    {
        return $this->dispatched;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (DomainEventInterface $event): string => $event->name(), $this->dispatched);
    }

    public function firstOf(string $name): ?DomainEventInterface
    {
        foreach ($this->dispatched as $event) {
            if ($event->name() === $name) {
                return $event;
            }
        }

        return null;
    }

    public function clear(): void
    {
        $this->dispatched = [];
    }
}
