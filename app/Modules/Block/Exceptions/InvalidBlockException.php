<?php

declare(strict_types=1);

namespace CMS\Modules\Block\Exceptions;

use InvalidArgumentException;

/**
 * Raised when a block payload is not a valid block document.
 */
final class InvalidBlockException extends InvalidArgumentException
{
}
