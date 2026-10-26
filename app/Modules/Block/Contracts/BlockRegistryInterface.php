<?php

declare(strict_types=1);

namespace CMS\Modules\Block\Contracts;

/**
 * Registry of block types.
 *
 * Core blocks are registered by the module; a plugin adds its own here. The
 * compiler never switches on a hardcoded block name outside of the built-in
 * renderer map, so a plugin block is validated and sanitized exactly like a core
 * one.
 *
 * FROZEN CONTRACT.
 */
interface BlockRegistryInterface
{
    /**
     * @param array{version?: int, dynamic?: bool, allows_raw_html?: bool} $definition
     */
    public function register(string $type, array $definition = []): void;

    public function has(string $type): bool;

    /**
     * @return array{type: string, version: int, dynamic: bool, allows_raw_html: bool}
     *
     * @throws \CMS\Modules\Block\Exceptions\UnknownBlockException
     */
    public function get(string $type): array;

    /**
     * @return array<string, array{type: string, version: int, dynamic: bool, allows_raw_html: bool}>
     */
    public function all(): array;

    /**
     * @return list<string>
     */
    public function types(): array;

    /**
     * Declared attribute schema for a block type, used by the sanitizer to keep
     * only attributes the type actually declares.
     *
     * @return array<string, string> attribute name => type
     *
     * @throws \CMS\Modules\Block\Exceptions\UnknownBlockException
     */
    public function schemaFor(string $type): array;

    /**
     * Current schema version for a block type.
     */
    public function versionFor(string $type): int;

    /**
     * Whether this block type is permitted to carry authored HTML.
     */
    public function allowsRawHtml(string $type): bool;
}
