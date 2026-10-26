<?php

declare(strict_types=1);

namespace CMS\Modules\Media\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when an upload fails validation: no file, a PHP upload error, a
 * suspicious filename, or a path that would escape the uploads directory.
 */
final class InvalidUploadException extends HttpException
{
    public function __construct(string $message = 'The uploaded file is not valid.', ?\Throwable $previous = null)
    {
        parent::__construct(422, $message, 'VALIDATION_ERROR', [], $previous);
    }
}
