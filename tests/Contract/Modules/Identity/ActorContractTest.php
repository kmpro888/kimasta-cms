<?php

declare(strict_types=1);

namespace Tests\Contract\Modules\Identity;

use CMS\Modules\Identity\Auth\Actor;
use CMS\Shared\Contracts\ActorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Support\TestCase;

/**
 * Frozen ActorInterface conformance.
 */
#[CoversClass(Actor::class)]
final class ActorContractTest extends TestCase
{
    #[Test]
    public function itImplementsTheFrozenInterface(): void
    {
        self::assertInstanceOf(ActorInterface::class, new Actor(1, 1, []));
    }

    #[Test]
    public function itExposesExactlyTheFrozenMethods(): void
    {
        $contract = new ReflectionClass(ActorInterface::class);
        $implementation = new ReflectionClass(Actor::class);

        foreach ($contract->getMethods() as $method) {
            self::assertTrue(
                $implementation->hasMethod($method->getName()),
                'Actor is missing contract method: ' . $method->getName()
            );

            $actual = $implementation->getMethod($method->getName());

            self::assertSame(
                (string) $method->getReturnType(),
                (string) $actual->getReturnType(),
                'Return type drift on: ' . $method->getName()
            );

            self::assertSame(
                count($method->getParameters()),
                count($actual->getParameters()),
                'Arity drift on: ' . $method->getName()
            );
        }
    }

    #[Test]
    public function canTakesCapabilityAndOptionalResource(): void
    {
        $method = (new ReflectionClass(Actor::class))->getMethod('can');
        $parameters = $method->getParameters();

        self::assertCount(2, $parameters);
        self::assertSame('capability', $parameters[0]->getName());
        self::assertSame('resource', $parameters[1]->getName());
        self::assertTrue($parameters[1]->isOptional());
    }

    #[Test]
    public function anonymousAndAuthenticatedShapesAreBothValid(): void
    {
        $anonymous = Actor::anonymous(3);
        $authenticated = new Actor(9, 3, ['content.read']);

        self::assertNull($anonymous->id());
        self::assertSame(9, $authenticated->id());
        self::assertFalse($anonymous->authenticated());
        self::assertTrue($authenticated->authenticated());
        self::assertSame(3, $anonymous->siteId());
        self::assertSame(3, $authenticated->siteId());
    }
}
