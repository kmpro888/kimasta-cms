<?php

declare(strict_types=1);

namespace CMS\Modules\Site\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when a Host header (or site id) does not map to any known site.
 *
 * The error code is frozen as SITE_NOT_FOUND. Status 404 avoids leaking
 * whether a host exists. Never fall back to site 1.
 */
final class SiteNotFoundException extends HttpException
{
    public function __construct(string $message = 'Site not found.', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, 'SITE_NOT_FOUND', [], $previous);
    }
}