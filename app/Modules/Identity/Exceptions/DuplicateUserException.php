<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Exceptions;

/**
 * Raised when a username or email already exists on the same site.
 *
 * Uniqueness is per site: the same email on a different site is legal and
 * must not raise this.
 */
final class DuplicateUserException extends IdentityException
{
    public function __construct(string $field)
    {
        parent::__construct('A user with this ' . $field . ' already exists on this site.');
    }
}