<?php

declare(strict_types=1);

namespace Tests\Security\Modules\Block;

use CMS\Modules\Block\Service\BlockSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * HTML sanitization.
 *
 * The `html` block is the only place authored markup re-enters the page, so this
 * allowlist is the last line of defence against stored XSS. A regex sanitizer is
 * bypassable; these tests attack a real DOM-based one.
 */
#[CoversClass(BlockSanitizer::class)]
final class BlockSanitizationTest extends TestCase
{
    private function sanitize(string $html): string
    {
        return (new BlockSanitizer())->sanitizeHtml($html);
    }

    #[Test]
    public function keepsPlainStructuralMarkup(): void
    {
        $out = $this->sanitize('<p>Hello <strong>world</strong></p>');

        self::assertStringContainsString('<p>', $out);
        self::assertStringContainsString('<strong>', $out);
        self::assertStringContainsString('Hello', $out);
    }

    /** @return array<string, array{0: string}> */
    public static function dangerousPayloads(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>'],
            'script with attributes' => ['<script type="text/javascript">alert(1)</script>'],
            'inline event handler' => ['<p onclick="alert(1)">click</p>'],
            'img onerror' => ['<img src="x" onerror="alert(1)">'],
            'svg onload' => ['<svg onload="alert(1)"></svg>'],
            'javascript href' => ['<a href="javascript:alert(1)">x</a>'],
            'javascript href with newline' => ["<a href=\"java\nscript:alert(1)\">x</a>"],
            'data uri href' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>'],
            'iframe' => ['<iframe src="https://evil.test"></iframe>'],
            'object tag' => ['<object data="evil.swf"></object>'],
            'embed tag' => ['<embed src="evil.swf">'],
            'style tag' => ['<style>body{display:none}</style>'],
            'form' => ['<form action="https://evil.test"><input name="x"></form>'],
            'base tag' => ['<base href="https://evil.test">'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.test">'],
        ];
    }

    #[Test]
    #[DataProvider('dangerousPayloads')]
    public function stripsDangerousMarkup(string $payload): void
    {
        $out = $this->sanitize($payload);

        self::assertStringNotContainsStringIgnoringCase('<script', $out);
        self::assertStringNotContainsStringIgnoringCase('onerror', $out);
        self::assertStringNotContainsStringIgnoringCase('onclick', $out);
        self::assertStringNotContainsStringIgnoringCase('onload', $out);
        self::assertStringNotContainsStringIgnoringCase('javascript:', $out);
        self::assertStringNotContainsStringIgnoringCase('<iframe', $out);
        self::assertStringNotContainsStringIgnoringCase('<object', $out);
        self::assertStringNotContainsStringIgnoringCase('<embed', $out);
        self::assertStringNotContainsStringIgnoringCase('<style', $out);
        self::assertStringNotContainsStringIgnoringCase('<form', $out);
        self::assertStringNotContainsStringIgnoringCase('<base', $out);
        self::assertStringNotContainsStringIgnoringCase('<meta', $out);
        self::assertStringNotContainsStringIgnoringCase('data:text/html', $out);
    }

    #[Test]
    public function removesDisallowedAttributesButKeepsTheElement(): void
    {
        $out = $this->sanitize('<p class="x" style="color:red" id="y">Text</p>');

        self::assertStringContainsString('<p>', $out);
        self::assertStringContainsString('Text', $out);
        self::assertStringNotContainsString('style=', $out);
        self::assertStringNotContainsString('onclick', $out);
    }

    #[Test]
    public function keepsSafeLinks(): void
    {
        $out = $this->sanitize('<a href="https://example.com/page" title="t">Link</a>');

        self::assertStringContainsString('href="https://example.com/page"', $out);
        self::assertStringContainsString('Link', $out);
    }

    #[Test]
    public function keepsRelativeLinks(): void
    {
        $out = $this->sanitize('<a href="/blog/post">Post</a>');

        self::assertStringContainsString('href="/blog/post"', $out);
    }

    #[Test]
    public function rejectsProtocolRelativeLinks(): void
    {
        $out = $this->sanitize('<a href="//evil.test/x">x</a>');

        self::assertStringNotContainsString('//evil.test', $out);
    }

    #[Test]
    public function targetBlankGetsSafeRel(): void
    {
        $out = $this->sanitize('<a href="https://example.com" target="_blank">x</a>');

        self::assertStringContainsString('rel="noopener noreferrer"', $out);
    }

    #[Test]
    public function preservesSafeImageAttributes(): void
    {
        $out = $this->sanitize('<img src="https://example.com/a.png" alt="A" width="10" height="20">');

        self::assertStringContainsString('src="https://example.com/a.png"', $out);
        self::assertStringContainsString('alt="A"', $out);
    }

    #[Test]
    public function unwrappingKeepsInnerText(): void
    {
        $out = $this->sanitize('<div><span>Kept text</span></div>');

        self::assertStringContainsString('Kept text', $out);
    }

    #[Test]
    public function commentsAreRemoved(): void
    {
        $out = $this->sanitize('<p>Before<!-- secret -->After</p>');

        self::assertStringNotContainsString('secret', $out);
        self::assertStringContainsString('Before', $out);
        self::assertStringContainsString('After', $out);
    }

    #[Test]
    public function emptyInputReturnsEmpty(): void
    {
        self::assertSame('', $this->sanitize(''));
        self::assertSame('', $this->sanitize('   '));
    }

    #[Test]
    public function escapeHtmlNeutralizesMarkup(): void
    {
        $out = (new BlockSanitizer())->escapeHtml('<script>alert(1)</script>');

        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $out);
    }

    #[Test]
    public function sanitizeDataKeepsOnlyDeclaredKeys(): void
    {
        $clean = (new BlockSanitizer())->sanitizeData(
            ['text' => 'Hello', 'sneaky' => 'value', 'level' => '3'],
            ['text' => 'string', 'level' => 'int']
        );

        self::assertSame(['text' => 'Hello', 'level' => 3], $clean);
    }

    #[Test]
    public function sanitizeDataCoercesArrayLeavesToScalars(): void
    {
        $clean = (new BlockSanitizer())->sanitizeData(
            ['items' => ['a', ['nested' => 'b'], new \stdClass()]],
            ['items' => 'array']
        );

        self::assertIsArray($clean['items']);
        self::assertSame('a', $clean['items'][0]);
        self::assertSame(['nested' => 'b'], $clean['items'][1]);
        // An object leaf is dropped rather than serialized.
        self::assertCount(2, $clean['items']);
    }
}
