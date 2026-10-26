<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Exceptions;

use RuntimeException;

/**
 * Raised when a `theme.json` manifest is missing required fields or is unreadable.
 *
 * Kept as a plain exception rather than an HttpException because it is a discovery
 * problem, reported to an administrator, not a request-level failure.
 */
final class InvalidThemeManifestException extends RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
