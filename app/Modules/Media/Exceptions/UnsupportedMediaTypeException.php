<?php

declare(strict_types=1);

namespace CMS\Modules\Media\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when a file's real MIME type is not in the image whitelist.
 */
final class UnsupportedMediaTypeException extends HttpException
{
    public function __construct(string $mime = '', ?\Throwable $previous = null)
    {
        parent::__construct(
            422,
            'That file type is not allowed.',
            'INVALID_MEDIA_TYPE',
            $mime === '' ? [] : ['mime' => $mime],
            $previous
        );
    }
}
