<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Exceptions;

/**
 * Raised when valid credentials belong to a disabled account.
 */
final class AccountDisabledException extends IdentityException
{
    public const MESSAGE = 'This account is not available.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
