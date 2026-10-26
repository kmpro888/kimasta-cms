<?php

declare(strict_types=1);

namespace CMS\Modules\Block\Exceptions;

use InvalidArgumentException;

/**
 * Raised when a block type is not registered.
 */
final class UnknownBlockException extends InvalidArgumentException
{
    public function __construct(string $type)
    {
        parent::__construct('Unknown block type: ' . $type);
    }
}
