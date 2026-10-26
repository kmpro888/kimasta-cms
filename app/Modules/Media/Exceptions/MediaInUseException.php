<?php

declare(strict_types=1);

namespace CMS\Modules\Media\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when deleting media that is still referenced by content.
 *
 * Deleting a referenced file would silently break a published page, so the
 * default is to refuse until the caller explicitly forces it.
 */
final class MediaInUseException extends HttpException
{
    public function __construct(int $references, ?\Throwable $previous = null)
    {
        parent::__construct(
            409,
            'This file is still used by existing content.',
            'INVALID_STATE_TRANSITION',
            ['references' => $references],
            $previous
        );
    }
}
