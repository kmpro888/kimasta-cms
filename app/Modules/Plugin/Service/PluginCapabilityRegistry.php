<?php

declare(strict_types=1);

namespace CMS\Modules\Plugin\Service;

/**
 * Capabilities declared by plugins.
 *
 * The core catalogue is a fixed list in Identity. A plugin cannot edit that list — it
 * is core — so it declares its capabilities here instead, and Identity merges them when
 * it seeds roles.
 *
 * The role grants declared by a plugin are defaults only: once a site has seeded, the
 * stored role/capability assignment is authoritative, so a plugin update cannot silently
 * hand itself new permissions.
 */
final class PluginCapabilityRegistry
{
    /**
     * @var array<string, array{name: string, description: string, plugin: string, roles: list<string>}>
     */
    private array $declared = [];

    /**
     * @param list<string> $roles
     */
    public function declare(
        string $plugin,
        string $key,
        string $name,
        string $description = '',
        array $roles = [],
    ): void {
        // A plugin may not overwrite a capability it does not own, and may not shadow a
        // core capability — doing so would let it hijack an existing permission check.
        if (isset($this->declared[$key]) && $this->declared[$key]['plugin'] !== $plugin) {
            return;
        }

        $this->declared[$key] = [
            'name' => $name,
            'description' => $description,
            'plugin' => $plugin,
            'roles' => array_values(array_filter($roles, 'is_string')),
        ];
    }

    /**
     * @return array<string, array{name: string, description: string}>
     */
    public function capabilities(): array
    {
        $out = [];

        foreach ($this->declared as $key => $meta) {
            $out[$key] = ['name' => $meta['name'], 'description' => $meta['description']];
        }

        return $out;
    }

    /**
     * @return array<string, list<string>> role slug => capability keys
     */
    public function roleGrants(): array
    {
        $out = [];

        foreach ($this->declared as $key => $meta) {
            foreach ($meta['roles'] as $role) {
                $out[$role][] = $key;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->declared);
    }

    public function ownerOf(string $key): ?string
    {
        return $this->declared[$key]['plugin'] ?? null;
    }

    /**
     * Remove every capability declared by a plugin, on uninstall.
     */
    public function removePlugin(string $plugin): void
    {
        foreach ($this->declared as $key => $meta) {
            if ($meta['plugin'] === $plugin) {
                unset($this->declared[$key]);
            }
        }
    }
}
