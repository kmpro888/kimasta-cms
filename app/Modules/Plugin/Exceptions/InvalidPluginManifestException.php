<?php

declare(strict_types=1);

namespace CMS\Modules\Plugin\Exceptions;

use RuntimeException;

/**
 * Raised when a `plugin.json` manifest is missing required fields or cannot be read.
 *
 * A plain exception rather than an HttpException: this is a discovery problem reported
 * to an operator, not a request-level failure. Discovery catches it and skips the
 * plugin.
 */
final class InvalidPluginManifestException extends RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
