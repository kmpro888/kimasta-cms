<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when a theme is not installed.
 */
final class ThemeNotFoundException extends HttpException
{
    public function __construct(string $name = '', ?\Throwable $previous = null)
    {
        parent::__construct(
            404,
            $name === '' ? 'Theme not found.' : 'Theme not found: ' . $name,
            'THEME_NOT_FOUND',
            [],
            $previous
        );
    }
}
