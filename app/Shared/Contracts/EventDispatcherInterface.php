<?php

declare(strict_types=1);

namespace CMS\Shared\Contracts;

use CMS\Shared\Contracts\DomainEventInterface;

/**
 * Synchronous domain event dispatcher.
 *
 * FROZEN CONTRACT.
 */
interface EventDispatcherInterface
{
    public function listen(
        string $event,
        callable $listener,
        int $priority = 10,
        bool $critical = false
    ): void;

    public function dispatch(DomainEventInterface $event): void;
}
