<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Exceptions;

/**
 * Raised for ANY failed login: unknown identifier, unknown site user, or
 * wrong password. The message is deliberately identical in all cases so an
 * attacker cannot tell which field was wrong.
 */
final class InvalidCredentialsException extends IdentityException
{
    public const MESSAGE = 'Invalid credentials.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
