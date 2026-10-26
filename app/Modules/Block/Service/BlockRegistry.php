<?php

declare(strict_types=1);

namespace CMS\Modules\Block\Service;

use CMS\Modules\Block\Contracts\BlockRegistryInterface;
use CMS\Modules\Block\Exceptions\UnknownBlockException;

/**
 * Registry of block types and their data schemas.
 *
 * Every block carries `type`, `version` and `data`. The schema stored alongside a
 * type is what the sanitizer uses to keep only declared attributes, so a crafted
 * editor payload cannot inject an undeclared key into stored content.
 *
 * Twenty core blocks are registered here. A plugin registers its own with
 * `register()` and is treated identically by validation, sanitization and
 * migration.
 */
final class BlockRegistry implements BlockRegistryInterface
{
    /**
     * Core block definitions: type => [version, dynamic, allows_raw_html, schema].
     *
     * Schema values are the expected attribute type, used by the sanitizer.
     *
     * @var array<string, array{version: int, dynamic: bool, allows_raw_html: bool, schema: array<string, string>}>
     */
    private const CORE = [
        'paragraph' => [
            'version' => 1,
            'dynamic' => false,
            'allows_raw_html' => false,
            'schema' => ['text' => 'string'],
        ],
        'heading' => [
            'version' => 1,
            'dynamic' => false,
            'allows_raw_html' => false,
            'schema' => ['text' => 'string', 'level' => 'int'],
        ],
        'image' => [
            'version' => 1,
            'dynamic' => false,
            'allows_raw_html' => false,
            'schema' => ['url' => 'string', 'alt' => 'string', 'caption' => 'string', 'media_id' => 'int'],
        ],
        'gallery' => [
            // v2 renamed `images` to `media_ids`. Old v1 documents are migrated
            // on read by BlockMigrator, which is what keeps stored content
            // readable across a schema change.
            'version' => 2,
            'dynamic' => false,
            'allows_raw_html' => false,
            'schema' => ['media_ids' => 'array', 'columns' => 'int'],
        ],
        'quote' => [
            'version' => 1,
            'dynamic' => false,
            'allows_raw_html' => false,
            'schema' => ['text' => 'string', 'cite' => 'string'],
        ],
        'list' => [
            'version' => 1,
            'dynamic' => false,
            'allows_raw_html' => false,
            'schema' => ['items' => 'array', 'ordered' => 'bool'],
        ],
        'table' => [
            'version' => 1,
            'dynamic' => false,
            'allows_raw_html' => false,
            'schema' => ['rows' => 'array', 'header' => 'bool'],
        ],
        'button' => [
            'version' => 1,
            'dynamic' => false,
            'allows_raw_html' => false,
            'schema' => ['text' => 'string', 'url' => 'string', 'style' => 'string'],
        ],
        'embed' => [
            'version' => 1,
            'dynamic' => true,
            'allows_raw_html' => false,
            'schema' => ['url' => 'string', 'provider' => 'string'],
        ],
        'video' => [
            'version' => 1,
            'dynamic' => false,
            'allows_raw_html' => false,
            'schema' => ['url' => 'string', 'poster' => 'string'],
        ],
        'code' => [
            'version' => 1,
            'dynamic' => false,
            'allows_raw_html' => false,
            'schema' => ['code' => 'string', 'language' => 'string'],
        ],
        'html' => [
            'version' => 1,
            'dynamic' => false,
            'allows_raw_html' => true,
            'schema' => ['html' => 'string'],
        ],
        'columns' => [
            'version' => 1,
            'dynamic' => false,
            'allows_raw_html' => false,
            'schema' => ['columns' => 'int', 'children' => 'array'],
        ],
        'section' => [
            'version' => 1,
            'dynamic' => false,
            'allows_raw_html' => false,
            'schema' => ['children' => 'array', 'background' => 'string'],
        ],
        'hero' => [
            'version' => 1,
            'dynamic' => false,
            'allows_raw_html' => false,
            'schema' => [
                'title' => 'string',
                'subtitle' => 'string',
                'image_url' => 'string',
                'cta_text' => 'string',
                'cta_url' => 'string',
            ],
        ],
        'cta' => [
            'version' => 1,
            'dynamic' => false,
            'allows_raw_html' => false,
            'schema' => [
                'title' => 'string',
                'text' => 'string',
                'button_text' => 'string',
                'button_url' => 'string',
            ],
        ],
        'faq' => [
            'version' => 1,
            'dynamic' => false,
            'allows_raw_html' => false,
            'schema' => ['items' => 'array'],
        ],
        'accordion' => [
            'version' => 1,
            'dynamic' => false,
            'allows_raw_html' => false,
            'schema' => ['items' => 'array'],
        ],
        'related_posts' => [
            'version' => 1,
            'dynamic' => true,
            'allows_raw_html' => false,
            'schema' => ['content_ids' => 'array', 'heading' => 'string'],
        ],
        'ad_slot' => [
            'version' => 1,
            'dynamic' => true,
            'allows_raw_html' => false,
            'schema' => ['slot' => 'string'],
        ],
    ];

    /** @var array<string, array{type: string, version: int, dynamic: bool, allows_raw_html: bool, schema: array<string, string>}> */
    private array $blocks = [];

    public function __construct()
    {
        foreach (self::CORE as $type => $definition) {
            $this->register($type, $definition);
        }
    }

    public function register(string $type, array $definition = []): void
    {
        $type = trim($type);

        if ($type === '') {
            throw new \InvalidArgumentException('Block type must not be empty.');
        }

        $schema = [];

        if (isset($definition['schema']) && is_array($definition['schema'])) {
            foreach ($definition['schema'] as $attribute => $kind) {
                if (is_string($attribute) && is_string($kind)) {
                    $schema[$attribute] = $kind;
                }
            }
        }

        $this->blocks[$type] = [
            'type' => $type,
            'version' => max(1, (int) ($definition['version'] ?? 1)),
            'dynamic' => (bool) ($definition['dynamic'] ?? false),
            'allows_raw_html' => (bool) ($definition['allows_raw_html'] ?? false),
            'schema' => $schema,
        ];
    }

    public function has(string $type): bool
    {
        return isset($this->blocks[$type]);
    }

    public function get(string $type): array
    {
        if (!isset($this->blocks[$type])) {
            throw new UnknownBlockException($type);
        }

        $definition = $this->blocks[$type];

        // The public shape deliberately omits the schema.
        return [
            'type' => $definition['type'],
            'version' => $definition['version'],
            'dynamic' => $definition['dynamic'],
            'allows_raw_html' => $definition['allows_raw_html'],
        ];
    }

    public function all(): array
    {
        $public = [];

        foreach ($this->blocks as $type => $definition) {
            $public[$type] = [
                'type' => $definition['type'],
                'version' => $definition['version'],
                'dynamic' => $definition['dynamic'],
                'allows_raw_html' => $definition['allows_raw_html'],
            ];
        }

        return $public;
    }

    public function types(): array
    {
        return array_keys($this->blocks);
    }

    /**
     * Declared attribute schema for a block type.
     *
     * @return array<string, string>
     *
     * @throws UnknownBlockException
     */
    public function schemaFor(string $type): array
    {
        if (!isset($this->blocks[$type])) {
            throw new UnknownBlockException($type);
        }

        return $this->blocks[$type]['schema'];
    }

    public function versionFor(string $type): int
    {
        return $this->get($type)['version'];
    }

    public function allowsRawHtml(string $type): bool
    {
        return $this->get($type)['allows_raw_html'];
    }
}
