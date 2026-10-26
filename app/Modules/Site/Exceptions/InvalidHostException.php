<?php

declare(strict_types=1);

namespace CMS\Modules\Site\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when a request Host header cannot be safely normalized.
 *
 * Malformed, empty, non-ASCII-invalid, or otherwise unusable Host values are
 * rejected rather than coerced, so a crafted header can never be steered onto
 * another tenant. Status 400 with the frozen VALIDATION_ERROR envelope code.
 */
final class InvalidHostException extends HttpException
{
    public function __construct(string $message = 'Invalid host header.', ?\Throwable $previous = null)
    {
        parent::__construct(400, $message, 'VALIDATION_ERROR', [], $previous);
    }
}