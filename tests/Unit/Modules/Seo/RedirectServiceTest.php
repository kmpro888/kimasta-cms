<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Seo;

use CMS\Modules\Seo\Exceptions\InvalidRedirectException;
use CMS\Modules\Seo\Exceptions\RedirectLoopException;
use CMS\Modules\Seo\Repository\RedirectRepositoryInterface;
use CMS\Modules\Seo\Service\RedirectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * Redirect validation and loop protection.
 *
 * A redirect table is a place where a small mistake produces an infinite browser
 * loop, so loop detection is tested as hard as the happy path.
 */
#[CoversClass(RedirectService::class)]
final class RedirectServiceTest extends TestCase
{
    /** @param array<string, array{target_url: string, status_code: int}> $initial */
    private function service(array $initial = []): RedirectService
    {
        $repository = new class ($initial) implements RedirectRepositoryInterface {
            /** @param array<string, array{target_url: string, status_code: int}> $rows */
            public function __construct(private array $rows)
            {
            }

            public function find(int $siteId, string $sourcePath): ?array
            {
                return $this->rows[$sourcePath] ?? null;
            }

            public function put(int $siteId, string $sourcePath, string $targetUrl, int $statusCode): void
            {
                $this->rows[$sourcePath] = ['target_url' => $targetUrl, 'status_code' => $statusCode];
            }

            public function delete(int $siteId, string $sourcePath): bool
            {
                if (!isset($this->rows[$sourcePath])) {
                    return false;
                }

                unset($this->rows[$sourcePath]);

                return true;
            }

            public function incrementHits(int $siteId, string $sourcePath, int $by): void
            {
                // The unit tests do not exercise counting; the integration test does.
            }

            public function listForSite(int $siteId): array
            {
                $list = [];

                foreach ($this->rows as $source => $row) {
                    $list[] = [
                        'source_path' => $source,
                        'target_url' => $row['target_url'],
                        'status_code' => $row['status_code'],
                    ];
                }

                return $list;
            }
        };

        // The counter needs a writable directory; it is never used by these assertions.
        return new RedirectService(
            $repository,
            new \CMS\Modules\Seo\Support\RedirectHitCounter(sys_get_temp_dir() . '/cms_redirect_hits_test')
        );
    }

    #[Test]
    public function storesAndResolvesAnExactRedirect(): void
    {
        $service = $this->service();
        $service->store(1, '/old-page', '/new-page', 301);

        $resolved = $service->resolve(1, '/old-page');

        self::assertNotNull($resolved);
        self::assertSame('/new-page', $resolved['target_url']);
        self::assertSame(301, $resolved['status_code']);
    }

    #[Test]
    public function lookupNormalizesTheIncomingPath(): void
    {
        $service = $this->service();
        $service->store(1, 'old-page/', '/new');

        // Query string, trailing slash and missing leading slash all resolve.
        self::assertNotNull($service->resolve(1, '/old-page?utm=x'));
        self::assertNotNull($service->resolve(1, '/old-page/'));
        self::assertNotNull($service->resolve(1, 'old-page'));
    }

    #[Test]
    public function aFullUrlAsSourceIsReducedToItsPath(): void
    {
        $service = $this->service();
        $service->store(1, 'https://example.com/old-page', '/new');

        self::assertNotNull($service->resolve(1, '/old-page'));
    }

    #[Test]
    public function aMissingRedirectResolvesToNull(): void
    {
        self::assertNull($this->service()->resolve(1, '/nothing-here'));
    }

    #[Test]
    public function anUnsupportedStatusCodeIsRejected(): void
    {
        $this->expectException(InvalidRedirectException::class);

        $this->service()->store(1, '/old', '/new', 200);
    }

    #[Test]
    public function statusCodes301And302AreAccepted(): void
    {
        $service = $this->service();
        $service->store(1, '/a', '/b', 301);
        $service->store(1, '/c', '/d', 302);

        self::assertSame(301, $service->resolve(1, '/a')['status_code']);
        self::assertSame(302, $service->resolve(1, '/c')['status_code']);
    }

    #[Test]
    public function anEmptyTargetIsRejected(): void
    {
        $this->expectException(InvalidRedirectException::class);

        $this->service()->store(1, '/old', '   ');
    }

    #[Test]
    public function aJavascriptTargetIsRejected(): void
    {
        $this->expectException(InvalidRedirectException::class);

        $this->service()->store(1, '/old', 'javascript:alert(1)');
    }

    #[Test]
    public function aProtocolRelativeTargetIsRejected(): void
    {
        $this->expectException(InvalidRedirectException::class);

        $this->service()->store(1, '/old', '//evil.test/x');
    }

    #[Test]
    public function anHttpsTargetIsAccepted(): void
    {
        $service = $this->service();
        $service->store(1, '/old', 'https://example.com/new');

        self::assertSame('https://example.com/new', $service->resolve(1, '/old')['target_url']);
    }

    #[Test]
    public function theSiteRootCannotBeRedirected(): void
    {
        $this->expectException(InvalidRedirectException::class);

        $this->service()->store(1, '/', '/elsewhere');
    }

    #[Test]
    public function aSelfRedirectIsRejected(): void
    {
        $this->expectException(RedirectLoopException::class);

        $this->service()->store(1, '/loop', '/loop');
    }

    #[Test]
    public function aTwoStepLoopIsRejected(): void
    {
        $service = $this->service(['/b' => ['target_url' => '/a', 'status_code' => 301]]);

        // /a -> /b would close the cycle /a -> /b -> /a.
        $this->expectException(RedirectLoopException::class);

        $service->store(1, '/a', '/b');
    }

    #[Test]
    public function aLongerChainThatClosesIsRejected(): void
    {
        $service = $this->service([
            '/b' => ['target_url' => '/c', 'status_code' => 301],
            '/c' => ['target_url' => '/d', 'status_code' => 301],
            '/d' => ['target_url' => '/a', 'status_code' => 301],
        ]);

        $this->expectException(RedirectLoopException::class);

        $service->store(1, '/a', '/b');
    }

    #[Test]
    public function aChainThatTerminatesIsAllowed(): void
    {
        $service = $this->service([
            '/b' => ['target_url' => '/c', 'status_code' => 301],
        ]);

        $service->store(1, '/a', '/b');

        self::assertSame('/b', $service->resolve(1, '/a')['target_url']);
    }

    #[Test]
    public function deleteRemovesTheRedirect(): void
    {
        $service = $this->service();
        $service->store(1, '/old', '/new');

        self::assertTrue($service->delete(1, '/old'));
        self::assertNull($service->resolve(1, '/old'));
    }

    #[Test]
    public function deletingSomethingAbsentReturnsFalse(): void
    {
        self::assertFalse($this->service()->delete(1, '/nope'));
    }

    #[Test]
    public function listReturnsEveryRedirect(): void
    {
        $service = $this->service();
        $service->store(1, '/a', '/b');
        $service->store(1, '/c', '/d');

        self::assertCount(2, $service->listForSite(1));
    }
}
