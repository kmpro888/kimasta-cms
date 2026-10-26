<?php

declare(strict_types=1);

namespace CMS\Modules\Taxonomy\Service;

use CMS\Modules\Taxonomy\Contracts\TaxonomyRegistryInterface;
use CMS\Modules\Taxonomy\Exceptions\UnknownTaxonomyException;

/**
 * In-memory taxonomy registry.
 *
 * `category` (hierarchical) and `tag` (flat) are registered here; a plugin adds
 * its own through this registry. No code path switches on a taxonomy name, so a
 * custom taxonomy behaves exactly like a built-in one.
 */
final class TaxonomyRegistry implements TaxonomyRegistryInterface
{
    /** @var array<string, array{name: string, label: string, hierarchical: bool, content_type: string|null}> */
    private array $taxonomies = [];

    public function __construct()
    {
        $this->register('category', ['label' => 'Category', 'hierarchical' => true]);
        $this->register('tag', ['label' => 'Tag', 'hierarchical' => false]);
    }

    public function register(string $name, array $definition = []): void
    {
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException('Taxonomy name must not be empty.');
        }

        $this->taxonomies[$name] = [
            'name' => $name,
            'label' => (string) ($definition['label'] ?? ucfirst($name)),
            'hierarchical' => (bool) ($definition['hierarchical'] ?? false),
            'content_type' => isset($definition['content_type']) && is_string($definition['content_type'])
                ? $definition['content_type']
                : null,
        ];
    }

    public function has(string $name): bool
    {
        return isset($this->taxonomies[$name]);
    }

    public function get(string $name): array
    {
        if (!isset($this->taxonomies[$name])) {
            throw new UnknownTaxonomyException($name);
        }

        return $this->taxonomies[$name];
    }

    public function all(): array
    {
        return $this->taxonomies;
    }

    public function names(): array
    {
        return array_keys($this->taxonomies);
    }
}
