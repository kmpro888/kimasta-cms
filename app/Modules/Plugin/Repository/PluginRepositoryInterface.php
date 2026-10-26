<?php

declare(strict_types=1);

namespace CMS\Modules\Plugin\Repository;

/**
 * Plugin installation and activation state.
 */
interface PluginRepositoryInterface
{
    /**
     * @param array<string, mixed> $manifest
     */
    public function install(string $name, string $version, array $manifest): void;

    public function uninstall(string $name): bool;

    public function isInstalled(string $name): bool;

    /**
     * @return array<string, string> name => version
     */
    public function installedVersions(): array;

    public function setActive(int $siteId, string $name, bool $active): void;

    public function isActiveFor(int $siteId, string $name): bool;

    /**
     * @return list<string>
     */
    public function activeFor(int $siteId): array;

    /**
     * @return list<int>
     */
    public function sitesFor(string $name): array;

    /**
     * @return list<string>
     */
    public function appliedMigrations(string $name): array;

    public function recordMigration(string $name, string $migration): void;
}
