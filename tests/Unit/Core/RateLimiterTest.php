<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CMS\Core\Security\RateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * Filesystem-backed rate limiter.
 *
 * No database and no sleeping: an expired window is seeded on disk instead of
 * waiting for real time to pass.
 */
#[CoversClass(RateLimiter::class)]
final class RateLimiterTest extends TestCase
{
    private const KEY = 'POST /api/v1/auth/login example.com 10.0.0.1';

    private function limiter(?string $directory = null): RateLimiter
    {
        return new RateLimiter($directory ?? $this->path('rate-limits'));
    }

    #[Test]
    public function theFirstNAttemptsAreAllowedAndTheNextIsRefused(): void
    {
        $limiter = $this->limiter();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            self::assertTrue(
                $limiter->attempt(self::KEY, 3, 60),
                'attempt ' . $attempt . ' must still be allowed'
            );
        }

        self::assertFalse($limiter->attempt(self::KEY, 3, 60));
        self::assertGreaterThan(0, $limiter->availableIn(self::KEY, 60));
    }

    #[Test]
    public function aDifferentKeyHasItsOwnCounter(): void
    {
        $limiter = $this->limiter();

        self::assertTrue($limiter->attempt(self::KEY, 1, 60));
        self::assertFalse($limiter->attempt(self::KEY, 1, 60));

        self::assertTrue($limiter->attempt('POST /api/v1/auth/login example.com 10.0.0.2', 1, 60));
    }

    #[Test]
    public function clearResetsTheCounter(): void
    {
        $limiter = $this->limiter();

        self::assertTrue($limiter->attempt(self::KEY, 1, 60));
        self::assertFalse($limiter->attempt(self::KEY, 1, 60));

        $limiter->clear(self::KEY);

        self::assertSame(0, $limiter->availableIn(self::KEY, 60));
        self::assertTrue($limiter->attempt(self::KEY, 1, 60));
    }

    #[Test]
    public function anExpiredWindowStartsAFreshCounter(): void
    {
        $directory = $this->path('rate-limits');
        $limiter = $this->limiter($directory);

        self::assertTrue($limiter->attempt(self::KEY, 2, 60));
        self::assertTrue($limiter->attempt(self::KEY, 2, 60));
        self::assertFalse($limiter->attempt(self::KEY, 2, 60));
        self::assertGreaterThan(0, $limiter->availableIn(self::KEY, 60));

        // Seed a window that opened more than decaySeconds ago instead of
        // sleeping: a stale window must not keep blocking the caller.
        file_put_contents(
            $directory . DIRECTORY_SEPARATOR . sha1(self::KEY) . '.json',
            json_encode(['window_start' => time() - 61, 'hits' => 99], JSON_THROW_ON_ERROR)
        );

        self::assertSame(0, $limiter->availableIn(self::KEY, 60));
        self::assertTrue($limiter->attempt(self::KEY, 2, 60));
    }

    #[Test]
    public function aCorruptFileIsTreatedAsAnEmptyCounterAndDeleted(): void
    {
        $directory = $this->path('rate-limits');
        mkdir($directory, 0775, true);

        $file = $directory . DIRECTORY_SEPARATOR . sha1(self::KEY) . '.json';
        file_put_contents($file, '{"window_start": "yesterday", "hits": ');

        $limiter = $this->limiter($directory);

        self::assertSame(0, $limiter->availableIn(self::KEY, 60));
        self::assertFileDoesNotExist($file);

        // And the key is usable again, exactly as if nothing had been recorded.
        self::assertTrue($limiter->attempt(self::KEY, 1, 60));
        self::assertFileExists($file);
    }

    #[Test]
    public function aMissingDirectoryIsCreatedWithoutRaising(): void
    {
        $directory = $this->path('nested' . DIRECTORY_SEPARATOR . 'rate-limits');
        self::assertDirectoryDoesNotExist($directory);

        $limiter = $this->limiter($directory);

        self::assertTrue($limiter->attempt(self::KEY, 1, 60));
        self::assertDirectoryExists($directory);
        self::assertFalse($limiter->attempt(self::KEY, 1, 60));
    }

    #[Test]
    public function anUncreatableDirectoryDegradesToAllowed(): void
    {
        // A regular file where the directory should be makes mkdir fail, which
        // stands in for a full or read-only partition on shared hosting.
        $blocker = $this->path('blocker');
        file_put_contents($blocker, 'not a directory');

        $limiter = $this->limiter($blocker . DIRECTORY_SEPARATOR . 'rate-limits');

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            self::assertTrue($limiter->attempt(self::KEY, 1, 60));
        }

        self::assertSame(0, $limiter->availableIn(self::KEY, 60));
    }

    #[Test]
    public function theStateFileHoldsTheWindowStartAndTheHitCount(): void
    {
        $directory = $this->path('rate-limits');
        $limiter = $this->limiter($directory);

        $limiter->attempt(self::KEY, 5, 60);

        $raw = file_get_contents($directory . DIRECTORY_SEPARATOR . sha1(self::KEY) . '.json');
        self::assertIsString($raw);

        $state = json_decode($raw, true);

        self::assertIsArray($state);
        self::assertSame(1, $state['hits']);
        self::assertIsInt($state['window_start']);
    }
}
