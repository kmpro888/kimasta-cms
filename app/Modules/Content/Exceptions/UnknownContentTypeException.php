<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Exceptions;

use InvalidArgumentException;

/**
 * Raised when an unregistered content type is requested.
 */
final class UnknownContentTypeException extends InvalidArgumentException
{
    public function __construct(string $key)
    {
        parent::__construct('Unknown content type: ' . $key);
    }
}
