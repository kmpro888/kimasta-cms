<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Taxonomy;

use CMS\Modules\Taxonomy\Support\TermSlug;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * Term slug normalization.
 */
#[CoversClass(TermSlug::class)]
final class TermSlugTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function cases(): array
    {
        return [
            'simple' => ['Technology', 'technology'],
            'spaces' => ['Web Development', 'web-development'],
            'punctuation' => ['News & Updates!', 'news-updates'],
            'accents' => ['Café Résumé', 'cafe-resume'],
            'collapses' => ['a   b___c', 'a-b-c'],
            'trims' => ['--hello--', 'hello'],
            'numbers' => ['Top 10 of 2026', 'top-10-of-2026'],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function makeNormalizes(string $input, string $expected): void
    {
        self::assertSame($expected, TermSlug::make($input));
    }

    #[Test]
    public function emptyInputFallsBackToTerm(): void
    {
        self::assertSame('term', TermSlug::make(''));
        self::assertSame('term', TermSlug::make('!!!'));
    }

    #[Test]
    public function isIdempotent(): void
    {
        $once = TermSlug::make('Some Term Name');

        self::assertSame($once, TermSlug::make($once));
    }

    #[Test]
    public function respectsTheColumnWidth(): void
    {
        $slug = TermSlug::make(str_repeat('word ', 100));

        self::assertLessThanOrEqual(180, strlen($slug));
        self::assertStringEndsNotWith('-', $slug);
    }
}
