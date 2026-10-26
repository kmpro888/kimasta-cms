<?php

declare(strict_types=1);

namespace CMS\Modules\Editorial\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when a transition is not permitted by the state machine.
 */
final class IllegalTransitionException extends HttpException
{
    public function __construct(string $from, string $to, ?\Throwable $previous = null)
    {
        parent::__construct(
            409,
            sprintf('Cannot move content from "%s" to "%s".', $from, $to),
            'INVALID_STATE_TRANSITION',
            ['from' => $from, 'to' => $to],
            $previous
        );
    }
}
