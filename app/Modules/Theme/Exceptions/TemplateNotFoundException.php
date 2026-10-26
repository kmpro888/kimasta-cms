<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when no template in the hierarchy exists.
 */
final class TemplateNotFoundException extends HttpException
{
    public function __construct(string $message = 'No template could render this page.', ?\Throwable $previous = null)
    {
        parent::__construct(500, $message, 'INTERNAL_ERROR', [], $previous);
    }
}
