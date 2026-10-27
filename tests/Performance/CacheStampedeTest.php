<?php

declare(strict_types=1);

namespace Tests\Performance;

use CMS\Core\Filesystem\LockInterface;
use CMS\Core\Http\Request;
use CMS\Modules\Cache\Contracts\PageCacheInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * Concurrent readers on a cold page, and on a warm one.
 *
 * The stampede lock existed but only serialised the writes: every waiting request re-rendered the
 * page, so a cold popular URL still cost N renders — orderly, and useless. The controller now takes
 * the lock before rendering and re-checks the cache after acquiring it.
 *
 * Proving that needs real concurrency: an in-process test cannot interleave two requests. These
 * tests start the application behind `php -S` with several workers, fire simultaneous requests with
 * `curl_multi`, and read the `X-Cache` header the application already sets.
 *
 * The first version of the "cold page" test passed *without* the fix, because rendering was fast
 * enough that later requests found the cache at the top of the request. It was replaced by one that
 * holds the lock and publishes a sentinel entry — deterministic, and it fails if the re-check is
 * removed. A concurrency test that cannot fail is worse than none.
 */
#[CoversClass(\CMS\Modules\Theme\Http\PublicPageController::class)]
final class CacheStampedeTest extends AppTestCase
{
    private const SITE_HOST = 'example.com';

    private const CONCURRENCY = 10;

    private static ?int $port = null;
    private static $process = null;
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$port = (int) substr($name, strrpos($name, ':') + 1);

        $root = dirname(__DIR__, 2);
        self::$log = $root . '/storage/temp/stampede-server.log';
        @unlink(self::$log);

        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            '-S', '127.0.0.1:' . self::$port,
            '-t', $root . '/public',
            $root . '/tests/E2E/router.php',
        ]));

        if (DIRECTORY_SEPARATOR !== '\\') {
            $command = 'exec ' . $command;
        }

        $env = array_merge((array) getenv(), ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false']);

        foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            $value = getenv($key);

            if ($value !== false) {
                $env[$key] = $value;
            }
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            // Several workers: `php -S` is single-threaded, and a concurrency test against one
            // worker would measure the server rather than the cache.
            $env['PHP_CLI_SERVER_WORKERS'] = '6';
        }

        self::$process = proc_open(
            $command,
            [1 => ['file', self::$log, 'w'], 2 => ['file', self::$log, 'a']],
            $pipes,
            $root,
            $env
        );

        for ($attempt = 0; $attempt < 150; $attempt++) {
            $connection = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);

            if (is_resource($connection)) {
                fclose($connection);

                return;
            }

            usleep(100_000);
        }

        self::$port = null;
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            $status = proc_get_status(self::$process);

            if (($status['pid'] ?? 0) > 0) {
                if (DIRECTORY_SEPARATOR === '\\') {
                    @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
                } else {
                    @exec('kill -TERM -' . (int) $status['pid'] . ' 2>/dev/null');
                }
            }

            proc_terminate(self::$process);
            @proc_close(self::$process);
            self::$process = null;
        }
    }

    private function urlByAddress(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    /**
     * @return array{0: \CurlMultiHandle, 1: list<\CurlHandle>}
     */
    private function startRequests(string $path, int $count): array
    {
        $multi = curl_multi_init();
        $handles = [];

        for ($i = 0; $i < $count; $i++) {
            $handle = curl_init($this->urlByAddress($path));
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Host: ' . self::SITE_HOST],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HEADER => true,
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }

        return [$multi, $handles];
    }

    /**
     * @param list<\CurlHandle> $handles
     *
     * @return list<array{cache: string, body: string}>
     */
    private function collect(\CurlMultiHandle $multi, array $handles): array
    {
        $results = [];

        foreach ($handles as $handle) {
            $raw = (string) curl_multi_getcontent($handle);
            $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
            $headers = substr($raw, 0, $headerSize);
            preg_match('/^X-Cache:\s*(\S+)/mi', $headers, $match);

            $results[] = [
                'status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
                'cache' => strtoupper($match[1] ?? 'NONE'),
                'body' => substr($raw, $headerSize),
            ];

            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }

        curl_multi_close($multi);

        return $results;
    }

    /**
     * @return list<array{status: int, cache: string, body: string}>
     */
    private function fireConcurrently(string $path, int $count): array
    {
        [$multi, $handles] = $this->startRequests($path, $count);

        do {
            $status = curl_multi_exec($multi, $running);

            if ($running) {
                curl_multi_select($multi, 0.05);
            }
        } while ($running && $status === CURLM_OK);

        return $this->collect($multi, $handles);
    }

    private function seedArticle(string $slug): void
    {
        $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => 'Stampede ' . $slug,
            'slug' => $slug,
            'author_id' => $this->makeUser('stampede-writer', 'contributor'),
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);
    }

    #[Test]
    public function aRequestThatWaitedServesTheEntryInsteadOfRebuildingIt(): void
    {
        if (self::$port === null) {
            self::markTestSkipped('The application server did not start.');
        }

        $slug = 'stampede-waited';
        $path = '/blog/' . $slug;
        $this->seedArticle($slug);

        $cache = $this->app->container()->get(PageCacheInterface::class);
        $cache->flushSite($this->siteId);

        // The name the controller uses. Reproduced rather than exposed: a public getter for a lock
        // name would be production surface added only for a test.
        $lockName = 'page:' . $this->siteId . ':' . sha1($path);
        $locks = $this->app->container()->get(LockInterface::class);

        self::assertTrue($locks->acquire($lockName, 1), 'The test must be able to hold the lock.');

        $sentinel = '<!doctype html><html lang="en"><head><title>Sentinel</title></head>'
            . '<body><main>served from the entry the winner wrote</main></body></html>';

        [$multi, $handles] = $this->startRequests($path, self::CONCURRENCY);

        // Drive the requests until they are all blocked on the lock, then publish the entry and
        // release it — what a slow winner does, only deterministic.
        $deadline = microtime(true) + 0.4;
        $published = false;

        while (true) {
            curl_multi_exec($multi, $running);

            if (!$published && microtime(true) >= $deadline) {
                $cache->put($this->siteId, $path, $sentinel);
                $locks->release($lockName);
                $published = true;
            }

            if (!$running) {
                break;
            }

            curl_multi_select($multi, 0.02);
        }

        if (!$published) {
            $locks->release($lockName);
        }

        $results = $this->collect($multi, $handles);
        $caches = array_column($results, 'cache');
        $bodies = array_column($results, 'body');

        // Without the re-check after acquiring the lock, every waiter rendered again and none of
        // these bodies would be the sentinel. This assertion is what makes the test worth having.
        self::assertSame(
            [],
            array_values(array_filter($bodies, static fn (string $b): bool => trim($b) !== trim($sentinel))),
            'Every waiter must serve the published entry. Cache headers: ' . implode(',', $caches)
        );

        self::assertSame(
            [],
            array_values(array_filter($caches, static fn (string $c): bool => $c !== 'HIT')),
            'and must report a cache hit. Got: ' . implode(',', $caches)
        );
    }

    #[Test]
    public function aWarmPageCostsNoRenderAtAll(): void
    {
        if (self::$port === null) {
            self::markTestSkipped('The application server did not start.');
        }

        $slug = 'stampede-warm';
        $this->seedArticle($slug);

        $cache = $this->app->container()->get(PageCacheInterface::class);
        $cache->flushSite($this->siteId);

        // Warm it over HTTP. The in-process app writes to this test's temporary storage, while the
        // server process uses the project's storage — warming the wrong one measured a cold page.
        $context = stream_context_create([
            'http' => ['header' => 'Host: ' . self::SITE_HOST . "\r\n", 'timeout' => 20],
        ]);
        @file_get_contents($this->urlByAddress('/blog/' . $slug), false, $context);

        $results = $this->fireConcurrently('/blog/' . $slug, self::CONCURRENCY);
        $caches = array_column($results, 'cache');

        self::assertSame(
            [],
            array_values(array_filter($caches, static fn (string $c): bool => $c !== 'HIT')),
            'A warm page must be served from cache for every concurrent reader. Got: ' . implode(',', $caches)
        );

        self::assertCount(1, array_unique(array_column($results, 'body')));
    }

    #[Test]
    public function theListingPathIsCacheableUnderConcurrency(): void
    {
        if (self::$port === null) {
            self::markTestSkipped('The application server did not start.');
        }

        $this->seedArticle('stampede-listing');
        $this->app->container()->get(PageCacheInterface::class)->flushSite($this->siteId);

        $results = $this->fireConcurrently('/blog', self::CONCURRENCY);

        self::assertSame(
            [],
            array_values(array_filter(array_column($results, 'status'), static fn (int $s): bool => $s !== 200)),
            'Every concurrent reader of the archive must be served.'
        );

        $caches = array_column($results, 'cache');

        self::assertLessThanOrEqual(
            2,
            count(array_keys($caches, 'MISS')),
            'The archive must not be rebuilt once per request. Got: ' . implode(',', $caches)
        );
    }
}
