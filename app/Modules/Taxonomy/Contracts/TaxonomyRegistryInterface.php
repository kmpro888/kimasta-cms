<?php

declare(strict_types=1);

namespace CMS\Modules\Taxonomy\Contracts;

/**
 * Registry of taxonomies.
 *
 * `category` and `tag` are registered by the module; a plugin adds its own
 * through this registry rather than editing an enum. Nothing in the engine
 * switches on a hardcoded taxonomy name, so a custom taxonomy behaves exactly
 * like a built-in one.
 *
 * FROZEN CONTRACT.
 */
interface TaxonomyRegistryInterface
{
    /**
     * @param array{label?: string, hierarchical?: bool, content_type?: string|null} $definition
     */
    public function register(string $name, array $definition = []): void;

    public function has(string $name): bool;

    /**
     * @return array{name: string, label: string, hierarchical: bool, content_type: string|null}
     *
     * @throws \CMS\Modules\Taxonomy\Exceptions\UnknownTaxonomyException
     */
    public function get(string $name): array;

    /**
     * @return array<string, array{name: string, label: string, hierarchical: bool, content_type: string|null}>
     */
    public function all(): array;

    /**
     * @return list<string>
     */
    public function names(): array;
}
