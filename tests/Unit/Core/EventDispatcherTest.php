<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CMS\Core\Events\DomainEvent;
use CMS\Core\Events\EventDispatcher;
use CMS\Core\Events\ListenerRegistry;
use CMS\Core\Logging\LoggerInterface;
use CMS\Shared\Contracts\DomainEventInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventDispatcher::class)]
#[CoversClass(ListenerRegistry::class)]
#[CoversClass(DomainEvent::class)]
final class EventDispatcherTest extends TestCase
{
    public function testDispatchInvokesListenerWithEvent(): void
    {
        $dispatcher = new EventDispatcher(new ListenerRegistry());
        $seen = [];

        $dispatcher->listen('content.article.published', static function (DomainEventInterface $event) use (&$seen): void {
            $seen[] = $event->get('article_id');
        });

        $dispatcher->dispatch(DomainEvent::create('content.article.published', ['article_id' => 7]));

        self::assertSame([7], $seen);
    }

    public function testListenersRunInPriorityOrder(): void
    {
        $dispatcher = new EventDispatcher(new ListenerRegistry());
        $order = [];

        $dispatcher->listen('a.b.c', static function () use (&$order): void {
            $order[] = 'low';
        }, 5);
        $dispatcher->listen('a.b.c', static function () use (&$order): void {
            $order[] = 'high';
        }, 50);
        $dispatcher->listen('a.b.c', static function () use (&$order): void {
            $order[] = 'mid';
        }, 10);

        $dispatcher->dispatch(DomainEvent::create('a.b.c'));

        self::assertSame(['high', 'mid', 'low'], $order);
    }

    public function testListenersAtSamePriorityKeepInsertionOrder(): void
    {
        $dispatcher = new EventDispatcher(new ListenerRegistry());
        $order = [];

        foreach (['first', 'second', 'third'] as $label) {
            $dispatcher->listen('a.b.c', static function () use (&$order, $label): void {
                $order[] = $label;
            }, 10);
        }

        $dispatcher->dispatch(DomainEvent::create('a.b.c'));

        self::assertSame(['first', 'second', 'third'], $order);
    }

    public function testDispatchToUnknownEventIsNoop(): void
    {
        $dispatcher = new EventDispatcher(new ListenerRegistry());

        $dispatcher->dispatch(DomainEvent::create('nobody.listens.here'));

        self::assertFalse($dispatcher->hasListeners('nobody.listens.here'));
    }

    public function testHasListenersReportsRegistration(): void
    {
        $dispatcher = new EventDispatcher(new ListenerRegistry());

        self::assertFalse($dispatcher->hasListeners('a.b.c'));

        $dispatcher->listen('a.b.c', static fn (): null => null);

        self::assertTrue($dispatcher->hasListeners('a.b.c'));
    }

    public function testNonCriticalListenerFailureIsLoggedAndSkipped(): void
    {
        $logger = new RecordingLogger();
        $dispatcher = new EventDispatcher(new ListenerRegistry(), $logger);
        $reached = false;

        $dispatcher->listen('a.b.c', static function (): void {
            throw new \RuntimeException('listener exploded');
        });
        $dispatcher->listen('a.b.c', static function () use (&$reached): void {
            $reached = true;
        }, 5);

        $dispatcher->dispatch(DomainEvent::create('a.b.c', [], 3));

        self::assertTrue($reached, 'Later listeners must still run.');
        self::assertCount(1, $logger->errors);
        self::assertSame('a.b.c', $logger->errors[0]['context']['event']);
        self::assertSame(3, $logger->errors[0]['context']['site_id']);
        self::assertSame('listener exploded', $logger->errors[0]['context']['message']);
    }

    public function testCriticalListenerFailureIsRethrown(): void
    {
        $dispatcher = new EventDispatcher(new ListenerRegistry());

        $dispatcher->listen('a.b.c', static function (): void {
            throw new \RuntimeException('cache invalidation failed');
        }, 10, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cache invalidation failed');

        $dispatcher->dispatch(DomainEvent::create('a.b.c'));
    }

    public function testEventNameIsExposedVerbatim(): void
    {
        $event = DomainEvent::create('content.article.updated', ['id' => 1], 2);

        self::assertSame('content.article.updated', $event->name());
        self::assertSame(2, $event->siteId());
        self::assertSame(1, $event->get('id'));
        self::assertNull($event->get('missing'));
    }
}

/**
 * Minimal logger double that records error calls.
 */
final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $errors = [];

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->errors[] = ['message' => $message, 'context' => $context];
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if ($level === 'error' || $level === 'critical' || $level === 'alert' || $level === 'emergency') {
            $this->errors[] = ['message' => $message, 'context' => $context];
        }
    }

    /** @param array<string, mixed> $context */
    public function withContext(array $context): static
    {
        return $this;
    }
}