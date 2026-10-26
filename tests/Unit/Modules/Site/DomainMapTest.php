<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Site;

use CMS\Modules\Site\Support\DomainMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * The compiled domain map is the device that lets a cached anonymous request
 * resolve its tenant with zero database queries, so its read path and its
 * regeneration contract are both pinned here.
 */
#[CoversClass(DomainMap::class)]
final class DomainMapTest extends TestCase
{
    #[Test]
    public function returnsAnEmptyMapWhenNothingWasCompiled(): void
    {
        $map = new DomainMap($this->path('cache'));

        self::assertSame([], $map->load());
        self::assertNull($map->siteIdFor('example.com'));
    }

    #[Test]
    public function writesAndReadsBackAMapping(): void
    {
        $map = new DomainMap($this->path('cache'));
        $written = $map->compile(['example.com' => 1, 'news.example.com' => 2]);

        self::assertSame(2, $written);
        self::assertFileExists($map->path());

        $fresh = new DomainMap($this->path('cache'));

        self::assertSame(['example.com' => 1, 'news.example.com' => 2], $fresh->load());
        self::assertSame(2, $fresh->siteIdFor('news.example.com'));
        self::assertNull($fresh->siteIdFor('unknown.test'));
    }

    #[Test]
    public function compiledFileIsValidPhpAndReturnsAnArray(): void
    {
        $map = new DomainMap($this->path('cache'));
        $map->compile(['example.com' => 7]);

        /** @var mixed $loaded */
        $loaded = require $map->path();

        self::assertIsArray($loaded);
        self::assertSame(7, $loaded['example.com']);
    }

    #[Test]
    public function recompilingReplacesThePreviousMap(): void
    {
        $map = new DomainMap($this->path('cache'));
        $map->compile(['old.example' => 1]);
        $map->compile(['new.example' => 2]);

        $fresh = new DomainMap($this->path('cache'));

        self::assertNull($fresh->siteIdFor('old.example'));
        self::assertSame(2, $fresh->siteIdFor('new.example'));
    }

    #[Test]
    public function memoIsUsedWithinOneInstance(): void
    {
        $map = new DomainMap($this->path('cache'));
        $map->compile(['example.com' => 3]);

        // Delete the file; the in-process memo must still answer, proving reads
        // do not hit disk repeatedly during a request.
        unlink($map->path());

        self::assertSame(3, $map->siteIdFor('example.com'));
    }

    #[Test]
    public function flushForcesAReread(): void
    {
        $map = new DomainMap($this->path('cache'));
        $map->compile(['example.com' => 3]);
        unlink($map->path());

        $map->flush();

        self::assertNull($map->siteIdFor('example.com'));
    }

    #[Test]
    public function ignoresInvalidEntriesInAHandEditedFile(): void
    {
        $directory = $this->path('cache');
        mkdir($directory, 0775, true);

        file_put_contents(
            $directory . '/domain-map.php',
            "<?php\nreturn ['good.test' => 5, 'bad.test' => 'not-an-int', 0 => 9, 'zero.test' => 0];\n"
        );

        $map = new DomainMap($directory);

        self::assertSame(['good.test' => 5], $map->load());
    }

    #[Test]
    public function deleteRemovesTheFileAndTheMemo(): void
    {
        $map = new DomainMap($this->path('cache'));
        $map->compile(['example.com' => 1]);

        $map->delete();

        self::assertFileDoesNotExist($map->path());
        self::assertNull($map->siteIdFor('example.com'));
    }

    #[Test]
    public function createsTheCacheDirectoryWhenMissing(): void
    {
        $map = new DomainMap($this->path('deep/nested/cache'));

        $map->compile(['example.com' => 1]);

        self::assertFileExists($map->path());
    }
}
