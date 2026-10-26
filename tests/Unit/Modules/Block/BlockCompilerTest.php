<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Block;

use CMS\Modules\Block\Service\BlockCompiler;
use CMS\Modules\Block\Service\BlockMigrator;
use CMS\Modules\Block\Service\BlockRegistry;
use CMS\Modules\Block\Service\BlockSanitizer;
use CMS\Modules\Content\Contracts\BlockCompilerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * Compiler output for every core block, plus the structural guarantees:
 * unknown blocks do not break a page, the renderer performs no I/O, and the
 * output is deterministic.
 */
#[CoversClass(BlockCompiler::class)]
final class BlockCompilerTest extends TestCase
{
    private function compiler(): BlockCompiler
    {
        $registry = new BlockRegistry();

        return new BlockCompiler(
            $registry,
            new BlockSanitizer(),
            new BlockMigrator($registry),
        );
    }

    /**
     * A minimal valid payload for each core block.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function coreBlockCases(): array
    {
        return [
            'paragraph' => [
                ['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body']],
                '<p>Body</p>',
            ],
            'heading' => [
                ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Title', 'level' => 2]],
                '<h2>Title</h2>',
            ],
            'image' => [
                ['type' => 'image', 'version' => 1, 'data' => ['url' => 'https://e.test/a.png', 'alt' => 'A']],
                '<img src="https://e.test/a.png" alt="A" loading="lazy">',
            ],
            'quote' => [
                ['type' => 'quote', 'version' => 1, 'data' => ['text' => 'Quoted', 'cite' => 'Someone']],
                '<blockquote>',
            ],
            'list' => [
                ['type' => 'list', 'version' => 1, 'data' => ['items' => ['One', 'Two']]],
                '<ul><li>One</li><li>Two</li></ul>',
            ],
            'table' => [
                ['type' => 'table', 'version' => 1, 'data' => ['rows' => [['A', 'B']], 'header' => true]],
                '<table><tr><th>A</th><th>B</th></tr></table>',
            ],
            'button' => [
                ['type' => 'button', 'version' => 1, 'data' => ['text' => 'Go', 'url' => '/go']],
                '<a class="button" href="/go">Go</a>',
            ],
            'video' => [
                ['type' => 'video', 'version' => 1, 'data' => ['url' => 'https://e.test/v.mp4']],
                '<video controls',
            ],
            'code' => [
                ['type' => 'code', 'version' => 1, 'data' => ['code' => 'echo 1;', 'language' => 'php']],
                '<pre><code class="language-php">echo 1;</code></pre>',
            ],
            'html' => [
                ['type' => 'html', 'version' => 1, 'data' => ['html' => '<p>Raw</p>']],
                '<p>Raw</p>',
            ],
            'hero' => [
                ['type' => 'hero', 'version' => 1, 'data' => ['title' => 'Welcome']],
                '<h2>Welcome</h2>',
            ],
            'cta' => [
                ['type' => 'cta', 'version' => 1, 'data' => ['title' => 'Join', 'text' => 'Today']],
                '<h3>Join</h3>',
            ],
            'faq' => [
                ['type' => 'faq', 'version' => 1, 'data' => ['items' => [['question' => 'Q?', 'answer' => 'A.']]]],
                '<details><summary>Q?</summary>',
            ],
            'accordion' => [
                ['type' => 'accordion', 'version' => 1, 'data' => ['items' => [['question' => 'Q2?', 'answer' => 'A2.']]]],
                '<details><summary>Q2?</summary>',
            ],
            'gallery' => [
                ['type' => 'gallery', 'version' => 2, 'data' => ['media_ids' => [1, 2], 'columns' => 3]],
                'data-media-id="1"',
            ],
            'columns' => [
                ['type' => 'columns', 'version' => 1, 'data' => ['columns' => 2, 'children' => [
                    ['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Left']],
                ]]],
                'block-column',
            ],
            'section' => [
                ['type' => 'section', 'version' => 1, 'data' => ['children' => [
                    ['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Inner']],
                ]]],
                '<section',
            ],
        ];
    }

    #[Test]
    public function everyStaticCoreBlockCompiles(): void
    {
        $compiler = $this->compiler();

        foreach (self::coreBlockCases() as $label => [$block, $expected]) {
            $html = $compiler->toHtml([$block]);

            self::assertStringContainsString(
                $expected,
                $html,
                'Block "' . $label . '" did not produce the expected markup.'
            );
        }
    }

    #[Test]
    public function itImplementsTheContentCompilerContract(): void
    {
        self::assertInstanceOf(BlockCompilerInterface::class, $this->compiler());
    }

    #[Test]
    public function dynamicBlocksRenderNothingWithoutPreparedData(): void
    {
        $compiler = $this->compiler();

        // embed falls back to a link when unresolved; related_posts and ad_slot
        // have no meaningful fallback and render nothing.
        self::assertSame('', $compiler->toHtml([
            ['type' => 'related_posts', 'version' => 1, 'data' => ['content_ids' => [1, 2]]],
        ]));

        self::assertSame('', $compiler->toHtml([
            ['type' => 'ad_slot', 'version' => 1, 'data' => ['slot' => 'sidebar']],
        ]));
    }

    #[Test]
    public function dynamicBlocksRenderWhenDataIsPrepared(): void
    {
        $compiler = $this->compiler();

        $blocks = [
            ['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Intro']],
            ['type' => 'related_posts', 'version' => 1, 'data' => ['heading' => 'Read next']],
        ];

        $html = $compiler->render($blocks, [
            1 => [['title' => 'Other Post', 'url' => '/blog/other']],
        ])['html'];

        self::assertStringContainsString('Read next', $html);
        self::assertStringContainsString('Other Post', $html);
        self::assertStringContainsString('href="/blog/other"', $html);
    }

    #[Test]
    public function dynamicBlockIndicesAreReported(): void
    {
        $indices = $this->compiler()->dynamicBlockIndices([
            ['type' => 'paragraph', 'data' => ['text' => 'A']],
            ['type' => 'related_posts', 'data' => []],
            ['type' => 'ad_slot', 'data' => []],
            ['type' => 'heading', 'data' => ['text' => 'B']],
        ]);

        self::assertSame([1, 2], $indices);
    }

    #[Test]
    public function anUnknownBlockIsSkippedWithoutBreakingThePage(): void
    {
        $html = $this->compiler()->toHtml([
            ['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Before']],
            ['type' => 'plugin_block_from_a_disabled_plugin', 'version' => 1, 'data' => []],
            ['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'After']],
        ]);

        self::assertStringContainsString('Before', $html);
        self::assertStringContainsString('After', $html);
    }

    #[Test]
    public function anOldGalleryVersionCompilesThroughMigration(): void
    {
        // v1 stored `images`; the compiler must migrate before rendering.
        $html = $this->compiler()->toHtml([
            ['type' => 'gallery', 'version' => 1, 'data' => ['images' => [5, 6]]],
        ]);

        self::assertStringContainsString('data-media-id="5"', $html);
        self::assertStringContainsString('data-media-id="6"', $html);
    }

    #[Test]
    public function plainTextIsProducedForEveryTextualBlock(): void
    {
        $plain = $this->compiler()->toPlainText([
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Title']],
            ['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body']],
            ['type' => 'list', 'version' => 1, 'data' => ['items' => ['One', 'Two']]],
        ]);

        self::assertStringContainsString('Title', $plain);
        self::assertStringContainsString('Body', $plain);
        self::assertStringContainsString('One', $plain);
        self::assertStringContainsString('Two', $plain);
        self::assertStringNotContainsString('<', $plain);
    }

    #[Test]
    public function outputIsDeterministic(): void
    {
        $compiler = $this->compiler();

        $blocks = [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Same', 'level' => 2]],
            ['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Every time']],
        ];

        self::assertSame($compiler->toHtml($blocks), $compiler->toHtml($blocks));
        self::assertSame($compiler->toPlainText($blocks), $compiler->toPlainText($blocks));
    }

    #[Test]
    public function escapedOutputPreventsScriptInjectionInTextBlocks(): void
    {
        $html = $this->compiler()->toHtml([
            ['type' => 'paragraph', 'version' => 1, 'data' => ['text' => '<script>alert(1)</script>']],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function theHtmlBlockIsStillSanitized(): void
    {
        $html = $this->compiler()->toHtml([
            ['type' => 'html', 'version' => 1, 'data' => ['html' => '<p>ok</p><script>alert(1)</script>']],
        ]);

        self::assertStringContainsString('<p>ok</p>', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    #[Test]
    public function undeclaredDataKeysAreDroppedBeforeRendering(): void
    {
        $html = $this->compiler()->toHtml([
            [
                'type' => 'paragraph',
                'version' => 1,
                'data' => ['text' => 'Clean', 'evil' => '<script>alert(1)</script>'],
            ],
        ]);

        self::assertStringContainsString('Clean', $html);
        self::assertStringNotContainsString('evil', $html);
        self::assertStringNotContainsString('alert', $html);
    }

    #[Test]
    public function anEmptyDocumentProducesEmptyOutput(): void
    {
        $compiler = $this->compiler();

        self::assertSame('', $compiler->toHtml([]));
        self::assertSame('', $compiler->toPlainText([]));
    }

    #[Test]
    public function headingLevelIsClamped(): void
    {
        $compiler = $this->compiler();

        self::assertStringContainsString('<h6>', $compiler->toHtml([
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Deep', 'level' => 99]],
        ]));

        self::assertStringContainsString('<h1>', $compiler->toHtml([
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Shallow', 'level' => -5]],
        ]));
    }
}
