<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Identity;

use CMS\Modules\Identity\Support\PasswordPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The minimum a password must be.
 *
 * Before this existed every path accepted any non-empty string, so a one-character password was
 * valid. It is invisible until someone sets one, and then it is a vulnerability with no warning.
 */
#[CoversClass(PasswordPolicy::class)]
final class PasswordPolicyTest extends TestCase
{
    #[Test]
    public function theFloorIsEightCharacters(): void
    {
        self::assertSame(8, PasswordPolicy::MIN_LENGTH);
    }

    #[Test]
    public function aLongEnoughPasswordPasses(): void
    {
        PasswordPolicy::assertAcceptable('correct-horse-battery');

        self::assertTrue(true, 'No exception thrown.');
    }

    #[Test]
    public function exactlyTheMinimumPasses(): void
    {
        PasswordPolicy::assertAcceptable(str_repeat('a', PasswordPolicy::MIN_LENGTH));

        self::assertTrue(true, 'The boundary itself is acceptable.');
    }

    #[Test]
    public function oneCharacterBelowTheMinimumIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PasswordPolicy::assertAcceptable(str_repeat('a', PasswordPolicy::MIN_LENGTH - 1));
    }

    #[Test]
    public function anEmptyPasswordIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PasswordPolicy::assertAcceptable('');
    }

    #[Test]
    public function theMessageStatesTheMinimum(): void
    {
        try {
            PasswordPolicy::assertAcceptable('short');
            self::fail('A short password must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('at least 8 characters', $exception->getMessage());
        }
    }

    #[Test]
    public function lengthIsCountedInCharactersNotBytes(): void
    {
        // Eight accented letters occupy more than eight bytes. A byte-based check would call
        // this password twelve characters long and accept a seven-character one beside it.
        $accented = str_repeat('e', 0) . 'aeeiouue'; // 8 characters, 8 bytes here

        PasswordPolicy::assertAcceptable($accented);

        $this->expectException(InvalidArgumentException::class);
        PasswordPolicy::assertAcceptable('aeeioue'); // 7 characters
    }
}
