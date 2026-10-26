<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Exceptions;

use InvalidArgumentException;

/**
 * Raised when a content payload is missing a required field or carries an
 * invalid value. Kept separate from InvalidStatusException so callers can tell
 * a status problem from a general validation problem.
 */
final class InvalidContentDataException extends InvalidArgumentException
{
}
