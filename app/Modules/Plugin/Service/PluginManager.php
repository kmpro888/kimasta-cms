<?php

declare(strict_types=1);

namespace CMS\Modules\Plugin\Service;

use CMS\Modules\Plugin\Contracts\HookRegistryInterface;
use CMS\Modules\Plugin\Contracts\PluginInterface;
use CMS\Modules\Plugin\Contracts\PluginManagerInterface;
use CMS\Modules\Plugin\Entity\PluginManifest;
use CMS\Modules\Plugin\Exceptions\InvalidPluginManifestException;
use CMS\Modules\Plugin\Exceptions\PluginNotInstalledException;
use CMS\Modules\Plugin\Exceptions\PluginNotFoundException;
use CMS\Modules\Plugin\Repository\PluginRepositoryInterface;
use Throwable;

/**
 * Plugin discovery and lifecycle.
 *
 * Discovery reads `plugin.json` for every directory under the plugin root. A directory
 * without a valid manifest is **not** a plugin — it is skipped, never fatal, because one
 * broken plugin must not take the site down.
 *
 * Installation runs the plugin's migrations; activation is a separate per-tenant
 * toggle. That split matters: a schema change is an operator action, while enabling a
 * feature on a site is an administrator action.
 *
 * Uninstall deliberately does **not** drop a plugin's data. Dropping tables as a side
 * effect of a toggle is how people lose production data.
 */
final class PluginManager implements PluginManagerInterface
{
    /** @var array<string, PluginManifest>|null */
    private ?array $discovered = null;

    /** @var array<string, PluginManifest> */
    private array $loaded = [];

    public function __construct(
        private readonly string $pluginRoot,
        private readonly PluginRepositoryInterface $repository,
        private readonly PluginMigrationRunner $migrations,
        private readonly PluginCapabilityRegistry $capabilities,
    ) {
    }

    public function discover(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $plugins = [];

        if (!is_dir($this->pluginRoot)) {
            return $this->discovered = [];
        }

        foreach (scandir($this->pluginRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $directory = $this->pluginRoot . DIRECTORY_SEPARATOR . $entry;

            if (!is_dir($directory)) {
                continue;
            }

            $manifest = $this->readManifest($directory, $entry);

            if ($manifest !== null) {
                $plugins[$manifest->name] = $manifest;
            }
        }

        ksort($plugins);

        return $this->discovered = $plugins;
    }

    public function installed(): array
    {
        $versions = $this->repository->installedVersions();
        $discovered = $this->discover();

        $out = [];

        foreach ($versions as $name => $version) {
            if (isset($discovered[$name])) {
                $out[$name] = $discovered[$name];
            }
        }

        return $out;
    }

    public function install(string $name): PluginManifest
    {
        $manifest = $this->requireManifest($name);

        // Migrations first: if the schema fails, the plugin must not be recorded as
        // installed, or every later boot would run against a half-created schema.
        $this->migrations->run($name, $manifest->migrationsPath());
        $this->repository->install($manifest->name, $manifest->version, $manifest->toArray());

        return $manifest;
    }

    public function uninstall(string $name): bool
    {
        $this->requireManifest($name);

        // Deactivation rows are removed by the repository before the plugin record.
        $this->capabilities->removePlugin($name);

        return $this->repository->uninstall($name);
    }

    public function activate(int $siteId, string $name): PluginManifest
    {
        $manifest = $this->requireManifest($name);

        if (!$this->repository->isInstalled($name)) {
            throw new PluginNotInstalledException($name);
        }

        $this->repository->setActive($siteId, $name, true);

        return $manifest;
    }

    public function deactivate(int $siteId, string $name): void
    {
        $this->requireManifest($name);
        $this->repository->setActive($siteId, $name, false);
    }

    public function isInstalled(string $name): bool
    {
        return $this->repository->isInstalled($name);
    }

    public function isActiveFor(int $siteId, string $name): bool
    {
        return $this->repository->isActiveFor($siteId, $name);
    }

    public function activeFor(int $siteId): array
    {
        return $this->repository->activeFor($siteId);
    }

    public function state(): array
    {
        $state = [];

        foreach ($this->discover() as $name => $manifest) {
            $state[$name] = [
                'installed' => $this->repository->isInstalled($name),
                'active_sites' => $this->repository->sitesFor($name),
            ];
        }

        return $state;
    }

    /**
     * Boot every installed plugin that is active on at least one tenant.
     *
     * Contributions (content types, taxonomies, blocks, routes) are global once a
     * plugin is active anywhere, because routing resolves before a tenant is known.
     * Route handlers are gated per tenant by `PluginRouteHandler`.
     *
     * @param callable(string): PluginContext $contextFactory
     *
     * @return list<string> plugins that booted
     */
    public function bootInstalled(callable $contextFactory, HookRegistryInterface $hooks): array
    {
        $booted = [];

        foreach ($this->repository->installedVersions() as $name => $_version) {
            if ($this->repository->sitesFor($name) === []) {
                // Installed but active nowhere: nothing to contribute.
                continue;
            }

            $manifest = $this->discover()[$name] ?? null;

            if ($manifest === null) {
                // Installed in the database but its files are gone. Skip rather than
                // break the site; the admin UI reports it as unavailable.
                continue;
            }

            $plugin = $this->load($manifest);

            if ($plugin === null) {
                continue;
            }

            // Contributions are attributed to this plugin so deactivation can remove
            // exactly its hooks.
            if (method_exists($hooks, 'beginOwner')) {
                $hooks->beginOwner($manifest->name);
            }

            try {
                $plugin->register($contextFactory($manifest->name));
            } catch (Throwable) {
                // A plugin that throws during registration must not break the site.
                continue;
            } finally {
                if (method_exists($hooks, 'endOwner')) {
                    $hooks->endOwner();
                }
            }

            $this->loaded[$manifest->name] = $manifest;
            $booted[] = $manifest->name;
        }

        return $booted;
    }

    /**
     * Instantiate a plugin's entry class.
     */
    private function load(PluginManifest $manifest): ?PluginInterface
    {
        $file = $manifest->entryFile();

        if (!is_file($file)) {
            return null;
        }

        $class = $manifest->entryClass;

        if (!class_exists($class)) {
            try {
                require_once $file;
            } catch (Throwable) {
                return null;
            }
        }

        if (!class_exists($class)) {
            return null;
        }

        try {
            $instance = new $class();
        } catch (Throwable) {
            return null;
        }

        return $instance instanceof PluginInterface ? $instance : null;
    }

    private function requireManifest(string $name): PluginManifest
    {
        $discovered = $this->discover();

        if (!isset($discovered[$name])) {
            throw new PluginNotFoundException($name);
        }

        return $discovered[$name];
    }

    /**
     * Read and validate a manifest. Returns null for anything unusable.
     */
    private function readManifest(string $directory, string $entry): ?PluginManifest
    {
        $path = $directory . DIRECTORY_SEPARATOR . 'plugin.json';

        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        try {
            return $this->validate($decoded, $entry, $directory);
        } catch (InvalidPluginManifestException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validate(array $data, string $entry, string $directory): PluginManifest
    {
        $version = isset($data['version']) && is_string($data['version']) ? $data['version'] : '';

        if ($version === '') {
            throw new InvalidPluginManifestException('Plugin manifest is missing a version: ' . $entry);
        }

        // The directory name is authoritative over the manifest `name`, so a copied
        // plugin cannot collide with the original it was copied from.
        $name = $entry;

        $class = isset($data['entry_class']) && is_string($data['entry_class'])
            ? $data['entry_class']
            : 'Plugin\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name))) . '\\Plugin';

        $requiresPlugins = isset($data['requires_plugins']) && is_array($data['requires_plugins'])
            ? array_values(array_filter($data['requires_plugins'], 'is_string'))
            : [];

        return new PluginManifest(
            name: $name,
            version: $version,
            title: isset($data['title']) && is_string($data['title']) ? $data['title'] : ucfirst($name),
            description: isset($data['description']) && is_string($data['description']) ? $data['description'] : null,
            author: isset($data['author']) && is_string($data['author']) ? $data['author'] : null,
            entryClass: $class,
            directory: $directory,
            requiresPlugins: $requiresPlugins,
            requires: isset($data['requires']) && is_array($data['requires']) ? $data['requires'] : [],
        );
    }
}
