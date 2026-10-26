<?php

declare(strict_types=1);

namespace CMS\Modules\Block\Contracts;

/**
 * Upgrades stored blocks to the current schema version.
 *
 * Old content must stay readable: a block saved as v1 is migrated on read rather
 * than being broken by a newer renderer. Migration is additive and idempotent.
 *
 * FROZEN CONTRACT.
 */
interface BlockMigrationInterface
{
    /**
     * Migrate a whole document. Unknown block types are preserved untouched so a
     * block whose plugin is temporarily disabled does not lose its data.
     *
     * @param array<int, array<string, mixed>> $blocks
     *
     * @return array<int, array<string, mixed>>
     */
    public function migrateDocument(array $blocks): array;

    /**
     * Migrate one block to its registry version.
     *
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    public function migrateBlock(array $block): array;
}
