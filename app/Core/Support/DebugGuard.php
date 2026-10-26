<?php

declare(strict_types=1);

namespace CMS\Core\Support;

use RuntimeException;

/**
 * Refuses to run a production site with debug output enabled.
 *
 * Debug mode appends stack traces, SQL and filesystem paths to error responses.
 * Combined with APP_ENV=production that turns every mistake into a disclosure to
 * whoever triggers it, so the mismatch is treated as a fatal misconfiguration
 * rather than a warning in a log nobody reads.
 */
final class DebugGuard
{
    public static function assertSafe(string $appEnv, bool $appDebug): void
    {
        if (strtolower($appEnv) !== 'production' || !$appDebug) {
            return;
        }

        throw new RuntimeException(
            'Unsafe configuration: APP_DEBUG must be false when APP_ENV=production, because debug '
            . 'output exposes stack traces, SQL and filesystem paths to visitors. Set APP_DEBUG=false '
            . 'in .env or in the process environment.'
        );
    }
}
