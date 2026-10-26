<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Contracts;

/**
 * Registry of content types.
 *
 * `post` and `page` are registered by the module. Plugins and later agents add
 * custom types through this registry rather than by editing an enum or a switch
 * statement, which is what keeps the content engine extensible.
 *
 * FROZEN CONTRACT.
 */
interface ContentTypeRegistryInterface
{
    /**
     * Register a type. Registering an existing key replaces its definition.
     *
     * @param array{label?: string, hierarchical?: bool, has_archive?: bool} $definition
     */
    public function register(string $key, array $definition = []): void;

    public function has(string $key): bool;

    /**
     * @return array{key: string, label: string, hierarchical: bool, has_archive: bool}
     *
     * @throws \CMS\Modules\Content\Exceptions\UnknownContentTypeException
     */
    public function get(string $key): array;

    /**
     * @return array<string, array{key: string, label: string, hierarchical: bool, has_archive: bool}>
     */
    public function all(): array;

    /**
     * @return list<string>
     */
    public function keys(): array;
}
