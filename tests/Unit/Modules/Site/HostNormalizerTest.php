<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Site;

use CMS\Modules\Site\Exceptions\InvalidHostException;
use CMS\Modules\Site\Support\HostNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * Host normalization is a security boundary: every tenant decision depends on
 * it, so both the accepted canonical forms and the rejections are pinned here.
 */
#[CoversClass(HostNormalizer::class)]
final class HostNormalizerTest extends TestCase
{
    #[Test]
    public function lowercasesTheHost(): void
    {
        self::assertSame('example.com', (new HostNormalizer())->normalize('EXAMPLE.COM'));
    }

    #[Test]
    public function stripsThePort(): void
    {
        self::assertSame('example.com', (new HostNormalizer())->normalize('example.com:8080'));
    }

    #[Test]
    public function stripsASingleTrailingDot(): void
    {
        self::assertSame('example.com', (new HostNormalizer())->normalize('example.com.'));
    }

    #[Test]
    public function trimsSurroundingWhitespace(): void
    {
        self::assertSame('example.com', (new HostNormalizer())->normalize('  example.com  '));
    }

    #[Test]
    public function preservesSubdomains(): void
    {
        self::assertSame('news.example.com', (new HostNormalizer())->normalize('NEWS.Example.com:443'));
    }

    #[Test]
    public function acceptsASingleLabelHost(): void
    {
        self::assertSame('localhost', (new HostNormalizer())->normalize('localhost'));
    }

    #[Test]
    public function acceptsHyphenatedLabels(): void
    {
        self::assertSame('my-site.co.uk', (new HostNormalizer())->normalize('my-site.co.uk'));
    }

    #[Test]
    public function acceptsPunycode(): void
    {
        self::assertSame('xn--bcher-kva.example', (new HostNormalizer())->normalize('xn--bcher-kva.example'));
    }

    /** @return array<string, array{0: string}> */
    public static function malformedHosts(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ['   '],
            'scheme prefix' => ['https://example.com'],
            'path suffix' => ['example.com/admin'],
            'query string' => ['example.com?x=1'],
            'fragment' => ['example.com#a'],
            'userinfo' => ['user@example.com'],
            'embedded space' => ['exa mple.com'],
            'header injection' => ["example.com\r\nX-Evil: 1"],
            'null byte' => ["example.com\0evil"],
            'non numeric port' => ['example.com:abc'],
            'port out of range' => ['example.com:99999'],
            'empty port' => ['example.com:'],
            'double colon' => ['example.com::80'],
            'ipv6 literal' => ['[::1]'],
            'leading hyphen label' => ['-example.com'],
            'trailing hyphen label' => ['example-.com'],
            'empty label' => ['example..com'],
            'underscore' => ['exa_mple.com'],
            'only a dot' => ['.'],
            'over-long label' => [str_repeat('a', 64) . '.com'],
        ];
    }

    #[Test]
    #[DataProvider('malformedHosts')]
    public function rejectsMalformedHosts(string $host): void
    {
        $this->expectException(InvalidHostException::class);

        (new HostNormalizer())->normalize($host);
    }

    #[Test]
    public function tryNormalizeReturnsNullInsteadOfThrowing(): void
    {
        $normalizer = new HostNormalizer();

        self::assertNull($normalizer->tryNormalize('https://example.com'));
        self::assertSame('example.com', $normalizer->tryNormalize('EXAMPLE.com:80'));
    }

    #[Test]
    public function rejectsAnOverLongHostname(): void
    {
        $long = str_repeat('abcdefgh.', 30) . 'com';

        $this->expectException(InvalidHostException::class);

        (new HostNormalizer())->normalize($long);
    }

    #[Test]
    public function normalizationIsIdempotent(): void
    {
        $normalizer = new HostNormalizer();
        $once = $normalizer->normalize('NEWS.Example.COM.:8080');

        self::assertSame($once, $normalizer->normalize($once));
    }
}
