<?php

declare(strict_types=1);

namespace CMS\Modules\Comment\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when a reply would exceed the bounded thread depth.
 */
final class CommentDepthExceededException extends HttpException
{
    public function __construct(string $message = 'This reply would exceed the maximum thread depth.', ?\Throwable $previous = null)
    {
        parent::__construct(409, $message, 'COMMENT_DEPTH_EXCEEDED', [], $previous);
    }
}
