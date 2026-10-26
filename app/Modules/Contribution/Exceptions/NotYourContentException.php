<?php

declare(strict_types=1);

namespace CMS\Modules\Contribution\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when an author reaches for content that is not theirs.
 *
 * A 404 rather than a 403: confirming that someone else's draft exists would leak the
 * existence of unpublished work, which is exactly what the contributor surface must
 * not do.
 */
final class NotYourContentException extends HttpException
{
    public function __construct(string $message = 'Content not found.', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, 'RESOURCE_NOT_FOUND', [], $previous);
    }
}
