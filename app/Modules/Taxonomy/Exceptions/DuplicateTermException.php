<?php

declare(strict_types=1);

namespace CMS\Modules\Taxonomy\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when a term slug already exists inside the same taxonomy on a site.
 */
final class DuplicateTermException extends HttpException
{
    public function __construct(string $message = 'A term with this slug already exists.', ?\Throwable $previous = null)
    {
        parent::__construct(409, $message, 'SLUG_ALREADY_EXISTS', [], $previous);
    }
}
