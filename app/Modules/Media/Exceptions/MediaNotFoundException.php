<?php

declare(strict_types=1);

namespace CMS\Modules\Media\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when media does not exist, or exists only on another site.
 */
final class MediaNotFoundException extends HttpException
{
    public function __construct(string $message = 'Media not found.', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, 'MEDIA_NOT_FOUND', [], $previous);
    }
}
