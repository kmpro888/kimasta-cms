<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when content does not exist, or exists only on another site.
 *
 * The two cases are deliberately identical: distinguishing them would confirm
 * the existence of another tenant's content.
 */
final class ContentNotFoundException extends HttpException
{
    public function __construct(string $message = 'Content not found.', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, 'CONTENT_NOT_FOUND', [], $previous);
    }
}
