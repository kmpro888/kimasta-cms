<?php

declare(strict_types=1);

namespace CMS\Modules\Revision\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when a revision id does not exist on this site.
 */
final class RevisionNotFoundException extends HttpException
{
    public function __construct(string $message = 'Revision not found.', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, 'RESOURCE_NOT_FOUND', [], $previous);
    }
}
