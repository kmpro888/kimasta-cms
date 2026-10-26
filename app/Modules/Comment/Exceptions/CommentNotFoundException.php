<?php

declare(strict_types=1);

namespace CMS\Modules\Comment\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when a comment id does not exist for this tenant.
 */
final class CommentNotFoundException extends HttpException
{
    public function __construct(string $message = 'Comment not found.', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, 'COMMENT_NOT_FOUND', [], $previous);
    }
}
