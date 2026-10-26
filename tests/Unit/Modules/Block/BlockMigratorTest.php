<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Block;

use CMS\Modules\Block\Service\BlockMigrator;
use CMS\Modules\Block\Service\BlockRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * Block version migration: old content must stay readable.
 */
#[CoversClass(BlockMigrator::class)]
final class BlockMigratorTest extends TestCase
{
    private function migrator(): BlockMigrator
    {
        return new BlockMigrator(new BlockRegistry());
    }

    #[Test]
    public function aCurrentVersionBlockIsUnchanged(): void
    {
        $block = ['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Hello']];

        self::assertSame($block, $this->migrator()->migrateBlock($block));
    }

    #[Test]
    public function galleryV1IsMigratedToV2(): void
    {
        $v1 = [
            'type' => 'gallery',
            'version' => 1,
            'data' => ['images' => [11, 22], 'columns' => 2],
        ];

        $migrated = $this->migrator()->migrateBlock($v1);

        self::assertSame(2, $migrated['version']);
        self::assertSame([11, 22], $migrated['data']['media_ids']);
        self::assertArrayNotHasKey('images', $migrated['data']);
        self::assertSame(2, $migrated['data']['columns']);
    }

    #[Test]
    public function migrationIsIdempotent(): void
    {
        $migrator = $this->migrator();

        $v1 = ['type' => 'gallery', 'version' => 1, 'data' => ['images' => [1]]];

        $once = $migrator->migrateBlock($v1);
        $twice = $migrator->migrateBlock($once);

        self::assertSame($once, $twice);
    }

    #[Test]
    public function aBlockWithoutAVersionIsTreatedAsV1(): void
    {
        $block = ['type' => 'gallery', 'data' => ['images' => [7]]];

        $migrated = $this->migrator()->migrateBlock($block);

        self::assertSame(2, $migrated['version']);
        self::assertSame([7], $migrated['data']['media_ids']);
    }

    #[Test]
    public function anUnknownBlockTypeIsPreservedUntouched(): void
    {
        // A temporarily disabled plugin must not lose its data.
        $block = ['type' => 'plugin_block', 'version' => 1, 'data' => ['x' => 1]];

        self::assertSame($block, $this->migrator()->migrateBlock($block));
    }

    #[Test]
    public function aBlockAlreadyAboveTheRegistryVersionIsLeftAlone(): void
    {
        $block = ['type' => 'gallery', 'version' => 9, 'data' => ['media_ids' => [1]]];

        $migrated = $this->migrator()->migrateBlock($block);

        self::assertSame(9, $migrated['version']);
    }

    #[Test]
    public function missingDataBecomesAnEmptyArray(): void
    {
        $migrated = $this->migrator()->migrateBlock(['type' => 'paragraph', 'version' => 1]);

        self::assertSame([], $migrated['data']);
    }

    #[Test]
    public function aWholeDocumentIsMigrated(): void
    {
        $document = [
            ['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'A']],
            ['type' => 'gallery', 'version' => 1, 'data' => ['images' => [5]]],
        ];

        $migrated = $this->migrator()->migrateDocument($document);

        self::assertCount(2, $migrated);
        self::assertSame([5], $migrated[1]['data']['media_ids']);
    }

    #[Test]
    public function nonArrayEntriesAreDropped(): void
    {
        $migrated = $this->migrator()->migrateDocument(['junk', 42, null]);

        self::assertSame([], $migrated);
    }
}
