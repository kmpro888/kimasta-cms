<?php

declare(strict_types=1);

namespace CMS\Modules\Plugin\Exceptions;

use RuntimeException;

/**
 * Raised when a plugin name has no valid manifest on disk.
 */
final class PluginNotFoundException extends RuntimeException
{
    public function __construct(string $name = '', ?\Throwable $previous = null)
    {
        parent::__construct(
            $name === '' ? 'Plugin not found.' : 'Plugin not found: ' . $name,
            0,
            $previous
        );
    }
}
