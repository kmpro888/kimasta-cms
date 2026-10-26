<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when redirect input is unusable: empty source, unsupported status code,
 * or a target that is not a path or http(s) URL.
 */
final class InvalidRedirectException extends HttpException
{
    public function __construct(string $message = 'The redirect is not valid.', ?\Throwable $previous = null)
    {
        parent::__construct(422, $message, 'VALIDATION_ERROR', [], $previous);
    }
}
