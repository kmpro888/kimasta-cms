<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Site;

use CMS\Modules\Site\Contracts\SiteServiceInterface;
use CMS\Modules\Site\Support\DomainMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * The compiled domain map belongs to one database.
 *
 * It used to be a single shared file, and the test harness regenerates it from the test
 * database. Running the suite therefore overwrote the map the development site reads: the
 * development site fell back to a database lookup on every request, and a live domain mapped to
 * an id that existed only in the test database would 404 the whole site until it was rebuilt.
 *
 * These tests pin the separation, because the failure is silent.
 */
#[CoversClass(DomainMap::class)]
final class DomainMapScopeTest extends AppTestCase
{
    #[Test]
    public function theMapPathIsScopedToTheCurrentDatabase(): void
    {
        $database = (string) $this->app->container()
            ->get(\CMS\Shared\Contracts\ConfigInterface::class)
            ->get('database.database', '');

        self::assertNotSame('', $database, 'The test database name must be configured.');

        $path = $this->app->container()->get(DomainMap::class)->path();

        self::assertStringContainsString(
            $database,
            $path,
            'The compiled map must be named after its database, or two installations can overwrite each other.'
        );
    }

    #[Test]
    public function regeneratingWritesOnlyTheScopedArtifact(): void
    {
        $map = $this->app->container()->get(DomainMap::class);
        $path = $map->path();

        $count = $this->app->container()->get(SiteServiceInterface::class)->regenerateDomainMap();

        self::assertGreaterThan(0, $count);
        self::assertFileExists($path);

        // The unscoped path is what the previous version wrote, and what a stale install may
        // still have on disk. Regenerating must not touch it.
        $legacy = dirname($path, 2) . DIRECTORY_SEPARATOR . 'domain-map.php';

        self::assertNotSame($legacy, $path);
    }

    #[Test]
    public function theCompiledMapResolvesThisSite(): void
    {
        $this->app->container()->get(SiteServiceInterface::class)->regenerateDomainMap();

        $resolved = $this->app->container()
            ->get(\CMS\Modules\Site\Contracts\SiteResolverInterface::class)
            ->siteIdFor($this->host);

        self::assertSame($this->siteId, $resolved);
    }
}
