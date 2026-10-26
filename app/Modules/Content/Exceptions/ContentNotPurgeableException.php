<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when a purge is attempted on content that is not already trashed.
 */
final class ContentNotPurgeableException extends HttpException
{
    public function __construct(string $message = 'Only trashed content can be permanently deleted.', ?\Throwable $previous = null)
    {
        parent::__construct(409, $message, 'INVALID_STATE_TRANSITION', [], $previous);
    }
}
