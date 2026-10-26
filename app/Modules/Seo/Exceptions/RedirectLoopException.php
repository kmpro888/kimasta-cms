<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when a redirect would point at itself or form a cycle.
 */
final class RedirectLoopException extends HttpException
{
    public function __construct(string $message = 'That redirect would create a loop.', ?\Throwable $previous = null)
    {
        parent::__construct(409, $message, 'INVALID_STATE_TRANSITION', [], $previous);
    }
}
