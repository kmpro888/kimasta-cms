<?php

declare(strict_types=1);

namespace CMS\Core\Events;

use CMS\Core\Logging\LoggerInterface;
use CMS\Shared\Contracts\DomainEventInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;
use Throwable;

/**
 * Synchronous in-process event dispatcher.
 *
 * Listener failures are handled by criticality: a critical listener aborts
 * dispatch and rethrows, while a non-critical listener is logged and skipped.
 */
final class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private readonly ListenerRegistry $registry,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function listen(
        string $event,
        callable $listener,
        int $priority = 10,
        bool $critical = false,
    ): void {
        $this->registry->add($event, $listener, $priority, $critical);
    }

    public function dispatch(DomainEventInterface $event): void
    {
        foreach ($this->registry->for($event->name()) as $entry) {
            try {
                ($entry['listener'])($event);
            } catch (Throwable $exception) {
                if ($entry['critical']) {
                    throw $exception;
                }

                $this->logger?->error('Non-critical listener failed', [
                    'event' => $event->name(),
                    'site_id' => $event->siteId(),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function hasListeners(string $event): bool
    {
        return $this->registry->has($event);
    }
}
