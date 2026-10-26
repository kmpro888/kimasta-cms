<?php

declare(strict_types=1);

namespace CMS\Modules\Editorial\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when the content row does not exist on this site.
 */
final class ContentMissingException extends HttpException
{
    public function __construct(string $message = 'Content not found.', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, 'CONTENT_NOT_FOUND', [], $previous);
    }
}
