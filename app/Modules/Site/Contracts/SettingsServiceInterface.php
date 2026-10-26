<?php

declare(strict_types=1);

namespace CMS\Modules\Site\Contracts;

/**
 * Per-site settings access backed by the compiled autoload cache.
 *
 * Autoload settings for the resolved site are compiled into
 * /storage/cache/system/site-settings-<siteId>.php and loaded once per
 * request; non-autoload keys fall through to a single indexed query.
 *
 * Every read/write is scoped to an explicit site id. There is no ambient
 * "current site" and no default site id — callers must always pass the
 * tenant they act on.
 */
interface SettingsServiceInterface
{
    /**
     * Read a setting for a site.
     *
     * @throws \CMS\Modules\Site\Exceptions\SiteNotFoundException
     */
    public function get(int $siteId, string $key, string $namespace = 'general', mixed $default = null): mixed;

    /**
     * Read all settings in a namespace (merged autoload + lazy).
     *
     * @return array<string, mixed>
     *
     * @throws \CMS\Modules\Site\Exceptions\SiteNotFoundException
     */
    public function all(int $siteId, string $namespace = 'general'): array;

    /**
     * Read every autoload setting for a site as a flat array.
     *
     * @return array<string, mixed>
     *
     * @throws \CMS\Modules\Site\Exceptions\SiteNotFoundException
     */
    public function autoloaded(int $siteId): array;

    /**
     * Persist a setting value and refresh the compiled autoload cache.
     *
     * @throws \CMS\Modules\Site\Exceptions\SiteNotFoundException
     */
    public function set(
        int $siteId,
        string $key,
        mixed $value,
        string $namespace = 'general',
        bool $autoload = false,
    ): void;

    /**
     * Delete a setting and refresh the compiled autoload cache.
     *
     * @throws \CMS\Modules\Site\Exceptions\SiteNotFoundException
     */
    public function forget(int $siteId, string $key, string $namespace = 'general'): void;

    /**
     * Drop the in-process and compiled setting cache for one site.
     */
    public function invalidate(int $siteId): void;
}