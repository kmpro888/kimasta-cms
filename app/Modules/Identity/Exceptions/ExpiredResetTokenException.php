<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Exceptions;

/**
 * Raised when a password reset token existed but is past its expiry.
 *
 * Carries the same public message as InvalidResetTokenException so a caller
 * cannot distinguish "expired" from "never existed" without being told so
 * explicitly by the flow that issued it.
 */
final class ExpiredResetTokenException extends IdentityException
{
    public const MESSAGE = 'This password reset link has expired.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
