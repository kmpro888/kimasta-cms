<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Content;

use CMS\Modules\Content\Contracts\ContentRepositoryInterface;
use CMS\Modules\Content\Entity\Content;
use CMS\Modules\Content\Entity\ContentSummary;
use CMS\Modules\Content\Service\SlugService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * Slug normalization and collision resolution.
 *
 * A slug is a public URL, so normalization must be deterministic: the same title
 * always yields the same base. Collision resolution is driven by an in-memory
 * set here so the pure logic is tested without a database.
 */
#[CoversClass(SlugService::class)]
final class SlugServiceTest extends TestCase
{
    /**
     * Repository stub backed by a slug => ownerId map, so `exceptId` forwarding
     * is genuinely exercised rather than stubbed away.
     *
     * @param array<string, int> $taken
     */
    private function service(array $taken = []): SlugService
    {
        $repository = new class ($taken) implements ContentRepositoryInterface {
            /** @param array<string, int> $taken */
            public function __construct(private array $taken)
            {
            }

            public function findById(int $id, int $siteId): ?Content
            {
                return null;
            }

            public function findBySlug(string $slug, string $type, int $siteId): ?Content
            {
                return null;
            }

            public function create(int $siteId, array $attributes): Content
            {
                throw new \LogicException('not used');
            }

            public function update(int $id, int $siteId, array $attributes): void
            {
            }

            public function trash(int $id, int $siteId, \DateTimeImmutable $at): bool
            {
                return false;
            }

            public function purge(int $id, int $siteId): bool
            {
                return false;
            }

            public function slugExists(string $slug, string $type, int $siteId, ?int $exceptId = null): bool
            {
                if (!array_key_exists($slug, $this->taken)) {
                    return false;
                }

                // A slug owned by the row being updated is not a collision.
                return $this->taken[$slug] !== $exceptId;
            }

            public function paginate(int $siteId, array $criteria = [], int $limit = 20, int $offset = 0): array
            {
                return [];
            }

            public function count(int $siteId, array $criteria = []): int
            {
                return 0;
            }
        };

        return new SlugService($repository);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function slugCases(): array
    {
        return [
            'simple' => ['Hello World', 'hello-world'],
            'already slug' => ['hello-world', 'hello-world'],
            'uppercase' => ['HELLO', 'hello'],
            'punctuation' => ['Hello, World!', 'hello-world'],
            'collapses separators' => ['a   b___c', 'a-b-c'],
            'trims hyphens' => ['--hello--', 'hello'],
            'numbers' => ['Top 10 Posts of 2026', 'top-10-posts-of-2026'],
            'accents' => ['Café Résumé', 'cafe-resume'],
            'underscores' => ['a_b_c', 'a-b-c'],
        ];
    }

    #[Test]
    #[DataProvider('slugCases')]
    public function slugifyNormalizes(string $input, string $expected): void
    {
        self::assertSame($expected, $this->service()->slugify($input));
    }

    #[Test]
    public function emptyInputFallsBackToUntitled(): void
    {
        self::assertSame('untitled', $this->service()->slugify(''));
        self::assertSame('untitled', $this->service()->slugify('   '));
        self::assertSame('untitled', $this->service()->slugify('!!!'));
    }

    #[Test]
    public function slugifyIsIdempotent(): void
    {
        $service = $this->service();
        $once = $service->slugify('Some Fancy Title!');

        self::assertSame($once, $service->slugify($once));
    }

    #[Test]
    public function slugIsTruncatedToTheColumnWidth(): void
    {
        $slug = $this->service()->slugify(str_repeat('word ', 100));

        self::assertLessThanOrEqual(220, strlen($slug));
        self::assertStringEndsNotWith('-', $slug);
    }

    #[Test]
    public function uniqueForReturnsTheBaseWhenFree(): void
    {
        self::assertSame('hello', $this->service()->uniqueFor('Hello', 'post', 1));
    }

    #[Test]
    public function uniqueForAppendsASuffixWhenTaken(): void
    {
        $service = $this->service(['hello' => 1, 'hello-2' => 2]);

        self::assertSame('hello-3', $service->uniqueFor('Hello', 'post', 1));
    }

    #[Test]
    public function uniqueForRespectsTheExcludedId(): void
    {
        // The only taken slug belongs to row 42, which is the row being updated,
        // so the base is available again for that same row.
        $service = $this->service(['hello' => 42]);

        self::assertSame('hello', $service->uniqueFor('Hello', 'post', 1, 42));
        self::assertSame('hello-2', $service->uniqueFor('Hello', 'post', 1));
    }

    #[Test]
    public function availabilityReflectsTheRepository(): void
    {
        $service = $this->service(['taken' => 7]);

        self::assertTrue($service->isAvailable('free', 'post', 1));
        self::assertFalse($service->isAvailable('taken', 'post', 1));
    }

    #[Test]
    public function emptySlugIsNeverAvailable(): void
    {
        self::assertFalse($this->service()->isAvailable('', 'post', 1));
    }

    #[Test]
    public function suffixedSlugStaysWithinTheColumnWidth(): void
    {
        $base = str_repeat('a', 219);
        $service = $this->service([$base => 1]);

        $slug = $service->uniqueFor($base, 'post', 1);

        self::assertLessThanOrEqual(220, strlen($slug));
        self::assertStringEndsWith('-2', $slug);
    }
}
