<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CMS\Core\Container\Container;
use CMS\Core\Container\Exceptions\BindingNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Container::class)]
final class ContainerTest extends TestCase
{
    public function testBindResolvesNewInstanceEachTime(): void
    {
        $container = new Container();
        $container->bind('service', static fn (): object => new \stdClass());

        $first = $container->get('service');
        $second = $container->get('service');

        self::assertInstanceOf(\stdClass::class, $first);
        self::assertNotSame($first, $second);
    }

    public function testSingletonReturnsSameInstance(): void
    {
        $container = new Container();
        $container->singleton('service', static fn (): object => new \stdClass());

        self::assertSame($container->get('service'), $container->get('service'));
    }

    public function testInstanceIsAlwaysShared(): void
    {
        $container = new Container();
        $object = new \stdClass();
        $container->instance('service', $object);

        self::assertSame($object, $container->get('service'));
        self::assertSame($object, $container->get('service'));
    }

    public function testMissingBindingThrows(): void
    {
        $container = new Container();

        $this->expectException(BindingNotFoundException::class);

        $container->get('nope');
    }

    public function testHasReportsBindings(): void
    {
        $container = new Container();
        $container->bind('a', static fn (): string => 'a');

        self::assertTrue($container->has('a'));
        self::assertFalse($container->has('b'));
    }

    public function testBindingReceivesContainer(): void
    {
        $container = new Container();
        $container->instance('value', 'injected');
        $container->bind('consumer', static fn (Container $c): string => $c->get('value'));

        self::assertSame('injected', $container->get('consumer'));
    }

    public function testRebindingClearsCachedInstance(): void
    {
        $container = new Container();
        $container->singleton('service', static fn (): string => 'first');
        self::assertSame('first', $container->get('service'));

        $container->singleton('service', static fn (): string => 'second');

        self::assertSame('second', $container->get('service'));
    }

    public function testForgetRemovesBinding(): void
    {
        $container = new Container();
        $container->bind('service', static fn (): string => 'x');
        $container->forget('service');

        self::assertFalse($container->has('service'));
    }

    public function testAliasResolvesToTarget(): void
    {
        $container = new Container();
        $container->singleton('real', static fn (): string => 'real');
        $container->alias('real', 'alias');

        self::assertSame('real', $container->get('alias'));
        self::assertTrue($container->has('alias'));
    }

    public function testCircularAliasThrows(): void
    {
        $container = new Container();
        $container->alias('a', 'b');
        $container->alias('b', 'a');

        $this->expectException(\RuntimeException::class);

        $container->get('a');
    }
}