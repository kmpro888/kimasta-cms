<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when a public path is already owned by another entity.
 *
 * The URL registry has a primary key on (site_id, path), so this is enforced by
 * the database as well as by the service pre-check.
 */
final class UrlCollisionException extends HttpException
{
    public function __construct(string $message = 'That URL is already in use.', ?\Throwable $previous = null)
    {
        parent::__construct(409, $message, 'URL_ALREADY_EXISTS', [], $previous);
    }
}
