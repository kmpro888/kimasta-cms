<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Identity;

use CMS\Modules\Identity\Auth\Actor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * Actor capability semantics.
 *
 * The critical property is that an anonymous actor can never pass can(), no
 * matter which capability string is asked for.
 */
#[CoversClass(Actor::class)]
final class ActorTest extends TestCase
{
    #[Test]
    public function anonymousActorIsNotAuthenticated(): void
    {
        $actor = Actor::anonymous(4);

        self::assertNull($actor->id());
        self::assertSame(4, $actor->siteId());
        self::assertFalse($actor->authenticated());
    }

    #[Test]
    public function anonymousActorCanNeverDoAnything(): void
    {
        $actor = Actor::anonymous(1);

        self::assertFalse($actor->can('content.publish'));
        self::assertFalse($actor->can('anything.at.all'));
        self::assertFalse($actor->can(''));
    }

    #[Test]
    public function authenticatedActorHoldsItsCapabilities(): void
    {
        $actor = new Actor(7, 2, ['content.read', 'content.create']);

        self::assertTrue($actor->authenticated());
        self::assertSame(7, $actor->id());
        self::assertTrue($actor->can('content.read'));
        self::assertTrue($actor->can('content.create'));
    }

    #[Test]
    public function missingCapabilityIsDenied(): void
    {
        $actor = new Actor(7, 2, ['content.read']);

        self::assertFalse($actor->can('content.publish'));
    }

    #[Test]
    public function capabilityMatchingIsExactNotPrefixBased(): void
    {
        $actor = new Actor(7, 2, ['content.read']);

        self::assertFalse($actor->can('content.rea'));
        self::assertFalse($actor->can('content'));
        self::assertFalse($actor->can('content.read.more'));
    }

    #[Test]
    public function duplicateCapabilitiesCollapse(): void
    {
        $actor = new Actor(7, 2, ['content.read', 'content.read', 'content.read']);

        self::assertSame(['content.read'], $actor->capabilities());
    }

    #[Test]
    public function emptyCapabilityStringsAreIgnored(): void
    {
        $actor = new Actor(7, 2, ['', 'content.read']);

        self::assertSame(['content.read'], $actor->capabilities());
        self::assertFalse($actor->can(''));
    }

    #[Test]
    public function nonPositiveSiteIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Actor(1, 0);
    }

    #[Test]
    public function nonPositiveUserIdIsRejectedWhenPresent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Actor(0, 1);
    }

    #[Test]
    public function nullUserIdIsAllowedForAnonymousConstruction(): void
    {
        $actor = new Actor(null, 1);

        self::assertFalse($actor->authenticated());
    }

    #[Test]
    public function hasIsACheapSetCheck(): void
    {
        $actor = new Actor(7, 2, ['a.b']);

        self::assertTrue($actor->has('a.b'));
        self::assertFalse($actor->has('a.c'));
    }
}
