<?php

declare(strict_types=1);

namespace CMS\Modules\Taxonomy\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when a term does not exist, or exists only on another site.
 */
final class TermNotFoundException extends HttpException
{
    public function __construct(string $message = 'Term not found.', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, 'TERM_NOT_FOUND', [], $previous);
    }
}
