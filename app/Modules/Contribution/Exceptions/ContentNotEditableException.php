<?php

declare(strict_types=1);

namespace CMS\Modules\Contribution\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when an author tries to edit content that is no longer theirs to change.
 *
 * Once a piece is submitted or in review, editing it would mean the reviewer is looking
 * at something different from what was submitted. The author must wait for a revision
 * request, which the editorial workflow grants.
 */
final class ContentNotEditableException extends HttpException
{
    public function __construct(
        string $message = 'This content is no longer editable. It is submitted or in review.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(409, $message, 'CONTENT_NOT_EDITABLE', [], $previous);
    }
}
