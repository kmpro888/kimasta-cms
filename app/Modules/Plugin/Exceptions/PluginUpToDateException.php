<?php

declare(strict_types=1);

namespace CMS\Modules\Plugin\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when an update is asked for a plugin that is already at the discovered version.
 *
 * A conflict rather than a silent success: an operator who clicks Update on something current
 * should be told so, not shown a success message that hides a no-op.
 */
final class PluginUpToDateException extends HttpException
{
    public function __construct(
        public readonly string $pluginName,
        public readonly string $installedVersion,
        public readonly string $availableVersion,
    ) {
        parent::__construct(
            409,
            'Plugin "' . $pluginName . '" is already at version ' . $installedVersion . '.',
            'CONFLICT'
        );
    }
}
