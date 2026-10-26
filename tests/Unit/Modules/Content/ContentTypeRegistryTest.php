<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Content;

use CMS\Modules\Content\Exceptions\UnknownContentTypeException;
use CMS\Modules\Content\Service\ContentTypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * Content type registry, including the custom-type requirement.
 */
#[CoversClass(ContentTypeRegistry::class)]
final class ContentTypeRegistryTest extends TestCase
{
    #[Test]
    public function postAndPageAreRegisteredByDefault(): void
    {
        $registry = new ContentTypeRegistry();

        self::assertTrue($registry->has('post'));
        self::assertTrue($registry->has('page'));
    }

    #[Test]
    public function pageIsHierarchicalAndPostIsNot(): void
    {
        $registry = new ContentTypeRegistry();

        self::assertTrue($registry->get('page')['hierarchical']);
        self::assertFalse($registry->get('post')['hierarchical']);
    }

    #[Test]
    public function aCustomTypeCanBeRegisteredAndUsed(): void
    {
        $registry = new ContentTypeRegistry();
        $registry->register('product', ['label' => 'Product', 'has_archive' => false]);

        self::assertTrue($registry->has('product'));
        self::assertSame('Product', $registry->get('product')['label']);
        self::assertFalse($registry->get('product')['has_archive']);
    }

    #[Test]
    public function registeringAgainReplacesTheDefinition(): void
    {
        $registry = new ContentTypeRegistry();
        $registry->register('product', ['label' => 'Old']);
        $registry->register('product', ['label' => 'New']);

        self::assertSame('New', $registry->get('product')['label']);
    }

    #[Test]
    public function unknownTypeThrows(): void
    {
        $this->expectException(UnknownContentTypeException::class);

        (new ContentTypeRegistry())->get('nope');
    }

    #[Test]
    public function emptyKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ContentTypeRegistry())->register('');
    }

    #[Test]
    public function keysListsEveryRegisteredType(): void
    {
        $registry = new ContentTypeRegistry();
        $registry->register('product');

        self::assertSame(['post', 'page', 'product'], $registry->keys());
    }

    #[Test]
    public function labelDefaultsToTheCapitalisedKey(): void
    {
        $registry = new ContentTypeRegistry();
        $registry->register('recipe');

        self::assertSame('Recipe', $registry->get('recipe')['label']);
    }
}
