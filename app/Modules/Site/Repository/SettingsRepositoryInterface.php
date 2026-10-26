<?php

declare(strict_types=1);

namespace CMS\Modules\Site\Repository;

/**
 * Per-site setting persistence for the site_settings table.
 *
 * Values are stored as JSON text so scalars, arrays and booleans round-trip
 * without a generic serialization format leaking into the database.
 */
interface SettingsRepositoryInterface
{
    /**
     * All autoload rows for a site, as namespace => key => decoded value.
     *
     * @return array<string, array<string, mixed>>
     */
    public function autoloaded(int $siteId): array;

    /**
     * All rows for one namespace, decoded.
     *
     * @return array<string, mixed>
     */
    public function forNamespace(int $siteId, string $namespace): array;

    public function find(int $siteId, string $namespace, string $key): mixed;

    public function put(
        int $siteId,
        string $namespace,
        string $key,
        string $encodedValue,
        bool $autoload,
    ): void;

    public function forget(int $siteId, string $namespace, string $key): void;
}
