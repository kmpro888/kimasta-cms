<?php

declare(strict_types=1);

namespace CMS\Modules\Plugin\Contracts;

use CMS\Modules\Plugin\Entity\PluginManifest;

/**
 * Plugin discovery and lifecycle.
 *
 * Lifecycle states:
 *
 *   discovered  - a directory with a valid manifest exists
 *   installed   - its migrations have run; it is known to the database
 *   active      - enabled for one tenant
 *
 * Install and activation are separate on purpose: installing runs schema changes,
 * which is an operator action, while activation is a per-site toggle an administrator
 * can flip.
 *
 * FROZEN CONTRACT.
 */
interface PluginManagerInterface
{
    /**
     * Every plugin directory with a valid manifest, installed or not.
     *
     * @return array<string, PluginManifest>
     */
    public function discover(): array;

    /**
     * Installed plugins, keyed by name.
     *
     * @return array<string, PluginManifest>
     */
    public function installed(): array;

    /**
     * Run an uninstalled plugin's migrations and record it as installed.
     *
     * @throws \CMS\Modules\Plugin\Exceptions\PluginNotFoundException
     */
    public function install(string $name): PluginManifest;

    /**
     * Deactivate everywhere, then remove the record. A plugin's own data is left alone:
     * dropping tables is destructive and must not happen as a side effect of a toggle.
     *
     * @throws \CMS\Modules\Plugin\Exceptions\PluginNotFoundException
     */
    public function uninstall(string $name): bool;

    /**
     * @throws \CMS\Modules\Plugin\Exceptions\PluginNotFoundException
     * @throws \CMS\Modules\Plugin\Exceptions\PluginNotInstalledException
     */
    public function activate(int $siteId, string $name): PluginManifest;

    /**
     * @throws \CMS\Modules\Plugin\Exceptions\PluginNotFoundException
     */
    public function deactivate(int $siteId, string $name): void;

    public function isInstalled(string $name): bool;

    public function isActiveFor(int $siteId, string $name): bool;

    /**
     * Plugin names active for a tenant.
     *
     * @return list<string>
     */
    public function activeFor(int $siteId): array;

    /**
     * Per-site activation state for the admin UI.
     *
     * @return array<string, array{installed: bool, active_sites: list<int>}>
     */
    public function state(): array;
}
