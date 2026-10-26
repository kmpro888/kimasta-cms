<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Block;

use CMS\Modules\Block\Exceptions\UnknownBlockException;
use CMS\Modules\Block\Service\BlockRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * Block registry: the 20 core blocks plus plugin registration.
 */
#[CoversClass(BlockRegistry::class)]
final class BlockRegistryTest extends TestCase
{
    /**
     * The exact core block list from the task pack.
     *
     * @return list<string>
     */
    private function coreTypes(): array
    {
        return [
            'paragraph', 'heading', 'image', 'gallery', 'quote', 'list', 'table',
            'button', 'embed', 'video', 'code', 'html', 'columns', 'section',
            'hero', 'cta', 'faq', 'accordion', 'related_posts', 'ad_slot',
        ];
    }

    #[Test]
    public function everyCoreBlockIsRegistered(): void
    {
        $registry = new BlockRegistry();

        foreach ($this->coreTypes() as $type) {
            self::assertTrue($registry->has($type), 'Missing core block: ' . $type);
        }

        self::assertCount(count($this->coreTypes()), $registry->types());
    }

    #[Test]
    public function dynamicBlocksAreMarkedDynamic(): void
    {
        $registry = new BlockRegistry();

        foreach (['embed', 'related_posts', 'ad_slot'] as $type) {
            self::assertTrue($registry->get($type)['dynamic'], $type . ' should be dynamic');
        }

        foreach (['paragraph', 'heading', 'image'] as $type) {
            self::assertFalse($registry->get($type)['dynamic'], $type . ' should be static');
        }
    }

    #[Test]
    public function onlyTheHtmlBlockAllowsRawHtml(): void
    {
        $registry = new BlockRegistry();

        self::assertTrue($registry->allowsRawHtml('html'));

        foreach (['paragraph', 'code', 'table', 'embed'] as $type) {
            self::assertFalse($registry->allowsRawHtml($type), $type . ' must not allow raw HTML');
        }
    }

    #[Test]
    public function aPluginCanRegisterABlock(): void
    {
        $registry = new BlockRegistry();

        $registry->register('pricing_table', [
            'version' => 1,
            'schema' => ['plans' => 'array'],
        ]);

        self::assertTrue($registry->has('pricing_table'));
        self::assertSame(['plans' => 'array'], $registry->schemaFor('pricing_table'));
    }

    #[Test]
    public function unknownBlockThrows(): void
    {
        $this->expectException(UnknownBlockException::class);

        (new BlockRegistry())->get('nope');
    }

    #[Test]
    public function emptyTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new BlockRegistry())->register('  ');
    }

    #[Test]
    public function schemaDeclaresOnlyKnownAttributeTypes(): void
    {
        $registry = new BlockRegistry();

        foreach ($registry->types() as $type) {
            foreach ($registry->schemaFor($type) as $attribute => $kind) {
                self::assertContains(
                    $kind,
                    ['string', 'int', 'bool', 'array'],
                    $type . '.' . $attribute . ' has an unsupported type: ' . $kind
                );
            }
        }
    }

    #[Test]
    public function galleryIsOnSchemaVersionTwo(): void
    {
        // v2 renamed `images` to `media_ids`; this is what makes version
        // migration testable against real old content.
        self::assertSame(2, (new BlockRegistry())->versionFor('gallery'));
    }

    #[Test]
    public function publicShapeOmitsTheSchema(): void
    {
        $definition = (new BlockRegistry())->get('paragraph');

        self::assertArrayNotHasKey('schema', $definition);
        self::assertSame(['type', 'version', 'dynamic', 'allows_raw_html'], array_keys($definition));
    }

    #[Test]
    public function reRegisteringReplacesTheDefinition(): void
    {
        $registry = new BlockRegistry();
        $registry->register('paragraph', ['version' => 3]);

        self::assertSame(3, $registry->versionFor('paragraph'));
    }
}
