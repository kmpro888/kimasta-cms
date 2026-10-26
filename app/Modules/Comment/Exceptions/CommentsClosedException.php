<?php

declare(strict_types=1);

namespace CMS\Modules\Comment\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when a content row does not accept comments.
 */
final class CommentsClosedException extends HttpException
{
    public function __construct(string $message = 'Comments are closed for this content.', ?\Throwable $previous = null)
    {
        parent::__construct(409, $message, 'COMMENTS_CLOSED', [], $previous);
    }
}
