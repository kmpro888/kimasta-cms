<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Exceptions;

/**
 * Raised when a password reset token is unknown, already consumed, or was
 * presented for the wrong site.
 *
 * All three cases share one message on purpose: distinguishing them would
 * tell an attacker whether a token ever existed.
 */
final class InvalidResetTokenException extends IdentityException
{
    public const MESSAGE = 'This password reset link is not valid.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}