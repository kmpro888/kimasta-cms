<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Contracts;

/**
 * Capabilities contributed by something other than the core catalogue.
 *
 * The core capability list is a fixed array in Identity. A module that introduces its
 * own permissions (most importantly the plugin platform, where a plugin declares what
 * it needs) cannot edit that array — it is core. So it implements this interface, and
 * Identity merges the result when it seeds roles.
 *
 * Identity declares the seam; the contributor implements it. That keeps the dependency
 * pointing inward: Identity never learns what a plugin is.
 *
 * FROZEN CONTRACT.
 */
interface ExtraCapabilityProviderInterface
{
    /**
     * @return array<string, array{name: string, description: string}>
     */
    public function extraCapabilities(): array;

    /**
     * Default role grants for those capabilities.
     *
     * @return array<string, list<string>> role slug => capability keys
     */
    public function extraRoleGrants(): array;
}
