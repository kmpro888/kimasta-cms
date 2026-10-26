<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Taxonomy;

use CMS\Modules\Taxonomy\Exceptions\UnknownTaxonomyException;
use CMS\Modules\Taxonomy\Service\TaxonomyRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * Taxonomy registry, including the custom-taxonomy requirement.
 */
#[CoversClass(TaxonomyRegistry::class)]
final class TaxonomyRegistryTest extends TestCase
{
    #[Test]
    public function categoryAndTagAreRegisteredByDefault(): void
    {
        $registry = new TaxonomyRegistry();

        self::assertTrue($registry->has('category'));
        self::assertTrue($registry->has('tag'));
    }

    #[Test]
    public function categoryIsHierarchicalAndTagIsFlat(): void
    {
        $registry = new TaxonomyRegistry();

        self::assertTrue($registry->get('category')['hierarchical']);
        self::assertFalse($registry->get('tag')['hierarchical']);
    }

    #[Test]
    public function aPluginCanRegisterACustomTaxonomy(): void
    {
        $registry = new TaxonomyRegistry();
        $registry->register('genre', ['label' => 'Genre', 'hierarchical' => true]);

        self::assertTrue($registry->has('genre'));
        self::assertSame('Genre', $registry->get('genre')['label']);
        self::assertTrue($registry->get('genre')['hierarchical']);
    }

    #[Test]
    public function customTaxonomyCanBeBoundToAContentType(): void
    {
        $registry = new TaxonomyRegistry();
        $registry->register('genre', ['content_type' => 'book']);

        self::assertSame('book', $registry->get('genre')['content_type']);
    }

    #[Test]
    public function unknownTaxonomyThrows(): void
    {
        $this->expectException(UnknownTaxonomyException::class);

        (new TaxonomyRegistry())->get('nope');
    }

    #[Test]
    public function emptyNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TaxonomyRegistry())->register('  ');
    }

    #[Test]
    public function namesListsEveryRegisteredTaxonomy(): void
    {
        $registry = new TaxonomyRegistry();
        $registry->register('genre');

        self::assertSame(['category', 'tag', 'genre'], $registry->names());
    }

    #[Test]
    public function labelDefaultsToTheCapitalisedName(): void
    {
        $registry = new TaxonomyRegistry();
        $registry->register('genre');

        self::assertSame('Genre', $registry->get('genre')['label']);
    }

    #[Test]
    public function reRegisteringReplacesTheDefinition(): void
    {
        $registry = new TaxonomyRegistry();
        $registry->register('genre', ['label' => 'Old']);
        $registry->register('genre', ['label' => 'New']);

        self::assertSame('New', $registry->get('genre')['label']);
    }
}
