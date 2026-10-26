<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when a status value is outside the canonical content status list.
 */
final class InvalidStatusException extends HttpException
{
    public function __construct(string $status, ?\Throwable $previous = null)
    {
        parent::__construct(
            422,
            'Unknown content status: ' . $status,
            'VALIDATION_ERROR',
            ['status' => $status],
            $previous
        );
    }
}
