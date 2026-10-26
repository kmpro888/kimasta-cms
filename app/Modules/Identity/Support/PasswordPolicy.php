<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Support;

use InvalidArgumentException;

/**
 * The minimum a password must be.
 *
 * Until now every path accepted any non-empty string, so a one-character password was valid.
 * That is invisible until someone sets one, and then it is a vulnerability with no warning.
 *
 * This lives in Identity and is applied by the service methods rather than by controllers, so
 * the API, the admin form and the reset flow cannot disagree about it. A rule enforced in one
 * entry point and not another is how a weak password gets in.
 */
final class PasswordPolicy
{
    /**
     * Eight characters. Not a strength guarantee -- there is no composition rule here on
     * purpose, because forced symbols produce `Password1!` -- just a floor that rules out the
     * accidental one-character password.
     */
    public const MIN_LENGTH = 8;

    /**
     * @throws InvalidArgumentException when the password is too short
     */
    public static function assertAcceptable(string $password): void
    {
        // Count characters, not bytes: a password with accents should not pass because it
        // occupies more bytes than it has letters.
        if (mb_strlen($password) < self::MIN_LENGTH) {
            throw new InvalidArgumentException(
                'Password must be at least ' . self::MIN_LENGTH . ' characters.'
            );
        }
    }
}
