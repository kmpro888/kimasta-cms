<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Content;

use CMS\Modules\Content\Service\PlainBlockCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * The default block compiler must escape everything.
 *
 * Until the real block engine (Agent 11) is wired, this fallback is what writes
 * `content_html`. A fallback that passed HTML through would be a stored-XSS
 * hole, so escaping is asserted explicitly.
 */
#[CoversClass(PlainBlockCompiler::class)]
final class PlainBlockCompilerTest extends TestCase
{
    #[Test]
    public function rendersAParagraph(): void
    {
        $html = (new PlainBlockCompiler())->toHtml([
            ['type' => 'paragraph', 'data' => ['text' => 'Hello world']],
        ]);

        self::assertSame('<p>Hello world</p>', $html);
    }

    #[Test]
    public function rendersHeadingsWithAClampedLevel(): void
    {
        $compiler = new PlainBlockCompiler();

        self::assertSame(
            '<h2>Title</h2>',
            $compiler->toHtml([['type' => 'heading', 'data' => ['text' => 'Title', 'level' => 2]]])
        );

        self::assertSame(
            '<h6>Deep</h6>',
            $compiler->toHtml([['type' => 'heading', 'data' => ['text' => 'Deep', 'level' => 99]]])
        );
    }

    #[Test]
    public function rendersLists(): void
    {
        $html = (new PlainBlockCompiler())->toHtml([
            ['type' => 'list', 'data' => ['items' => ['One', 'Two']]],
        ]);

        self::assertSame('<ul><li>One</li><li>Two</li></ul>', $html);
    }

    #[Test]
    public function rendersAnOrderedListWhenAsked(): void
    {
        $html = (new PlainBlockCompiler())->toHtml([
            ['type' => 'list', 'data' => ['items' => ['First'], 'ordered' => true]],
        ]);

        self::assertSame('<ol><li>First</li></ol>', $html);
    }

    #[Test]
    public function escapesScriptTagsInParagraphs(): void
    {
        $html = (new PlainBlockCompiler())->toHtml([
            ['type' => 'paragraph', 'data' => ['text' => '<script>alert(1)</script>']],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function escapesAttributeInjectionInImages(): void
    {
        $html = (new PlainBlockCompiler())->toHtml([
            ['type' => 'image', 'data' => ['url' => 'x" onerror="alert(1)', 'alt' => 'a"b']],
        ]);

        self::assertStringNotContainsString('onerror="alert', $html);
        self::assertStringContainsString('&quot;', $html);
    }

    #[Test]
    public function imageWithoutAUrlRendersNothing(): void
    {
        $html = (new PlainBlockCompiler())->toHtml([
            ['type' => 'image', 'data' => ['alt' => 'no source']],
        ]);

        self::assertSame('', $html);
    }

    #[Test]
    public function plainTextCollectsBlockText(): void
    {
        $plain = (new PlainBlockCompiler())->toPlainText([
            ['type' => 'heading', 'data' => ['text' => 'Title']],
            ['type' => 'paragraph', 'data' => ['text' => 'Body']],
        ]);

        self::assertSame("Title\n\nBody", $plain);
    }

    #[Test]
    public function plainTextJoinsListItemsOnSeparateLines(): void
    {
        $plain = (new PlainBlockCompiler())->toPlainText([
            ['type' => 'list', 'data' => ['items' => ['One', 'Two']]],
        ]);

        self::assertSame("One\nTwo", $plain);
    }

    #[Test]
    public function nonArrayBlocksAreSkipped(): void
    {
        $compiler = new PlainBlockCompiler();

        self::assertSame('', $compiler->toHtml(['not-a-block', 42, null]));
        self::assertSame('', $compiler->toPlainText(['not-a-block']));
    }

    #[Test]
    public function emptyBlockPayloadProducesEmptyOutput(): void
    {
        $compiler = new PlainBlockCompiler();

        self::assertSame('', $compiler->toHtml([]));
        self::assertSame('', $compiler->toPlainText([]));
    }
}
