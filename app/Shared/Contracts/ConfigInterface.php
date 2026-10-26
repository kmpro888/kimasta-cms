<?php

declare(strict_types=1);

namespace CMS\Shared\Contracts;

/**
 * Immutable read access to configuration values using dot notation.
 *
 * Example: `$config->get('database.host')`.
 */
interface ConfigInterface
{
    /**
     * @example get('database.host')
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Determine whether a configuration key exists.
     */
    public function has(string $key): bool;
}
