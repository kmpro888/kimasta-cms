<?php

declare(strict_types=1);

namespace CMS\Modules\Block\Service;

use CMS\Modules\Block\Contracts\BlockMigrationInterface;
use CMS\Modules\Block\Contracts\BlockRegistryInterface;

/**
 * Upgrades stored block documents to the current schema version.
 *
 * Three rules make old content safe to keep:
 *  - A block whose type is not registered is preserved untouched. A temporarily
 *    disabled plugin must not silently lose its data.
 *  - Migration is applied on read, so a stored v1 document keeps rendering after
 *    the registry moves to v2.
 *  - Each step is idempotent and additive; a document already at the current
 *    version is returned unchanged.
 */
final class BlockMigrator implements BlockMigrationInterface
{
    public function __construct(private readonly BlockRegistryInterface $registry)
    {
    }

    public function migrateDocument(array $blocks): array
    {
        $migrated = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $migrated[] = $this->migrateBlock($block);
        }

        return $migrated;
    }

    public function migrateBlock(array $block): array
    {
        $type = isset($block['type']) && is_string($block['type']) ? $block['type'] : '';

        if ($type === '' || !$this->registry->has($type)) {
            // Unknown type: keep as-is rather than destroying it.
            return $block;
        }

        $target = $this->registry->versionFor($type);
        $version = isset($block['version']) && is_int($block['version']) ? $block['version'] : 1;

        if (!isset($block['data']) || !is_array($block['data'])) {
            $block['data'] = [];
        }

        while ($version < $target) {
            $step = $this->step($type, $version);

            if ($step === null) {
                // No migration path: stop and leave the version as-is so the
                // document is not marked as something it is not.
                break;
            }

            $block['data'] = $step($block['data']);
            $version++;
        }

        $block['version'] = $version;

        return $block;
    }

    /**
     * @return (callable(array<string, mixed>): array<string, mixed>)|null
     */
    private function step(string $type, int $fromVersion): ?callable
    {
        return match ([$type, $fromVersion]) {
            ['gallery', 1] => static function (array $data): array {
                // v1 stored `images`; v2 stores `media_ids`.
                if (isset($data['images']) && !isset($data['media_ids'])) {
                    $data['media_ids'] = $data['images'];
                }

                unset($data['images']);

                return $data;
            },
            default => null,
        };
    }
}
