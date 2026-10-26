<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Service;

use CMS\Modules\Content\Contracts\ContentTypeRegistryInterface;
use CMS\Modules\Content\Exceptions\UnknownContentTypeException;

/**
 * In-memory content type registry.
 *
 * `post` and `page` are registered here; anything else is registered by a later
 * agent or a plugin. Nothing in the engine switches on a hardcoded type name, so
 * a custom type behaves identically to a built-in one.
 */
final class ContentTypeRegistry implements ContentTypeRegistryInterface
{
    /** @var array<string, array{key: string, label: string, hierarchical: bool, has_archive: bool}> */
    private array $types = [];

    public function __construct()
    {
        // Built-in core types.
        $this->register('post', ['label' => 'Post', 'has_archive' => true]);
        $this->register('page', ['label' => 'Page', 'hierarchical' => true]);
    }

    public function register(string $key, array $definition = []): void
    {
        $key = trim($key);

        if ($key === '') {
            throw new \InvalidArgumentException('Content type key must not be empty.');
        }

        $this->types[$key] = [
            'key' => $key,
            'label' => (string) ($definition['label'] ?? ucfirst($key)),
            'hierarchical' => (bool) ($definition['hierarchical'] ?? false),
            'has_archive' => (bool) ($definition['has_archive'] ?? true),
        ];
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function get(string $key): array
    {
        if (!isset($this->types[$key])) {
            throw new UnknownContentTypeException($key);
        }

        return $this->types[$key];
    }

    public function all(): array
    {
        return $this->types;
    }

    public function keys(): array
    {
        return array_keys($this->types);
    }
}
