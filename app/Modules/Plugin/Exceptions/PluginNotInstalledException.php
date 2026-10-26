<?php

declare(strict_types=1);

namespace CMS\Modules\Plugin\Exceptions;

use RuntimeException;

/**
 * Raised when activation is attempted before installation.
 */
final class PluginNotInstalledException extends RuntimeException
{
    public function __construct(string $name = '', ?\Throwable $previous = null)
    {
        parent::__construct(
            $name === '' ? 'Plugin is not installed.' : 'Plugin is not installed: ' . $name,
            0,
            $previous
        );
    }
}
