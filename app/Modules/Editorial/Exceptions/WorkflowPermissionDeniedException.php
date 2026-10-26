<?php

declare(strict_types=1);

namespace CMS\Modules\Editorial\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when the actor lacks the capability a transition requires.
 */
final class WorkflowPermissionDeniedException extends HttpException
{
    public function __construct(string $capability, ?\Throwable $previous = null)
    {
        parent::__construct(
            403,
            'You are not allowed to perform this action.',
            'FORBIDDEN',
            ['required_capability' => $capability],
            $previous
        );
    }
}
