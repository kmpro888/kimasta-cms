<?php

declare(strict_types=1);

namespace CMS\Modules\Media\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when an upload exceeds the configured size limit.
 */
final class MediaTooLargeException extends HttpException
{
    public function __construct(int $limitBytes, ?\Throwable $previous = null)
    {
        parent::__construct(
            422,
            'The uploaded file is too large.',
            'MEDIA_TOO_LARGE',
            ['max_bytes' => $limitBytes],
            $previous
        );
    }
}
