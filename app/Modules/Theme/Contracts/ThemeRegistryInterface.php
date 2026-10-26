<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Contracts;

use CMS\Modules\Theme\Entity\ThemeManifest;

/**
 * Theme discovery, inspection and activation.
 *
 * Themes are filesystem artifacts described by a `theme.json` manifest. Discovery
 * reads that manifest and validates it; it never executes theme code, so a broken
 * theme cannot break the admin.
 *
 * FROZEN CONTRACT.
 */
interface ThemeRegistryInterface
{
    /**
     * Discover every valid theme under the themes root.
     *
     * @return array<string, ThemeManifest>
     */
    public function all(): array;

    public function has(string $name): bool;

    /**
     * @throws \CMS\Modules\Theme\Exceptions\ThemeNotFoundException
     */
    public function get(string $name): ThemeManifest;

    /**
     * Theme currently assigned to a site, or the fallback when none is set.
     */
    public function activeFor(int $siteId): ThemeManifest;

    /**
     * Assign a theme to a site and emit `theme.activated`.
     *
     * @throws \CMS\Modules\Theme\Exceptions\ThemeNotFoundException
     */
    public function activate(int $siteId, string $name): ThemeManifest;

    /**
     * Absolute path to a theme's directory.
     *
     * @throws \CMS\Modules\Theme\Exceptions\ThemeNotFoundException
     */
    public function pathFor(string $name): string;
}
