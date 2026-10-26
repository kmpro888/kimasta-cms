<?php

declare(strict_types=1);

namespace CMS\Core\Security;

use CMS\Core\Logging\LoggerInterface;
use Throwable;

/**
 * Fixed-window attempt counter backed by the filesystem.
 *
 * The production target forbids Redis and long-running services, so the counter
 * follows the same idiom as the page cache: one small JSON file per key, written
 * to a temporary file and renamed into place so a reader never sees a partial
 * write. A missing or corrupt file simply means "no attempts yet".
 *
 * Public methods never throw. Rate limiting is a defence, not a dependency: a
 * limiter that takes the login page down is worse than no limiter, so every
 * filesystem failure degrades to "allowed" and is logged for the operator.
 */
final class RateLimiter
{
    /**
     * Serialises the read-modify-write for the whole directory.
     *
     * rename() keeps readers from seeing a torn file, but it does not stop two
     * parallel requests from reading the same hit count and both writing
     * count + 1, which is exactly how a brute-force run arrives. One lock file
     * per directory closes that gap without adding a file per key.
     */
    private const LOCK_FILE = '.lock';

    public function __construct(
        private readonly string $directory,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Record one hit and report whether the attempt is still within the limit.
     */
    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $maxAttempts = max(1, $maxAttempts);
        $decaySeconds = max(1, $decaySeconds);

        $handle = null;

        try {
            $handle = $this->lock();

            $now = time();
            $state = $this->load($key);

            // A window that has run out is not evidence against the caller, so
            // the count restarts instead of decaying gradually.
            if ($state === null || $now - $state['window_start'] >= $decaySeconds) {
                $state = ['window_start' => $now, 'hits' => 0];
            }

            $state['hits']++;
            $this->store($key, $state);

            return $state['hits'] <= $maxAttempts;
        } catch (Throwable $exception) {
            $this->report($key, $exception);

            return true;
        } finally {
            $this->unlock($handle);
        }
    }

    /**
     * Seconds until the current window resets.
     *
     * Zero means there is nothing to wait for: no window is recorded, or the
     * recorded one has already expired.
     */
    public function availableIn(string $key, int $decaySeconds): int
    {
        $decaySeconds = max(1, $decaySeconds);

        try {
            $state = $this->load($key);

            if ($state === null) {
                return 0;
            }

            $remaining = $state['window_start'] + $decaySeconds - time();

            return $remaining > 0 ? $remaining : 0;
        } catch (Throwable $exception) {
            $this->report($key, $exception);

            return 0;
        }
    }

    /**
     * Forget a key.
     *
     * A successful login must not be punished by the typos that came before it.
     */
    public function clear(string $key): void
    {
        try {
            $file = $this->path($key);

            if (is_file($file)) {
                @unlink($file);
            }
        } catch (Throwable $exception) {
            $this->report($key, $exception);
        }
    }

    /**
     * @return array{window_start: int, hits: int}|null
     */
    private function load(string $key): ?array
    {
        $file = $this->path($key);

        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        // A truncated or hand-edited file must not become a permanent block,
        // so anything that is not a well-formed counter is discarded.
        if (
            !is_array($decoded)
            || !is_int($decoded['window_start'] ?? null)
            || !is_int($decoded['hits'] ?? null)
            || $decoded['window_start'] < 0
            || $decoded['hits'] < 0
        ) {
            @unlink($file);

            return null;
        }

        return [
            'window_start' => $decoded['window_start'],
            'hits' => $decoded['hits'],
        ];
    }

    /**
     * @param array{window_start: int, hits: int} $state
     */
    private function store(string $key, array $state): void
    {
        $directory = rtrim($this->directory, '/\\');

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $payload = json_encode($state, JSON_UNESCAPED_SLASHES);

        if (!is_string($payload)) {
            return;
        }

        $file = $this->path($key);
        $temp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $payload) === false) {
            return;
        }

        @chmod($temp, 0644);

        if (!@rename($temp, $file)) {
            @unlink($temp);
        }
    }

    /**
     * @return resource|null
     */
    private function lock(): mixed
    {
        $directory = rtrim($this->directory, '/\\');

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }

        $handle = @fopen($directory . DIRECTORY_SEPARATOR . self::LOCK_FILE, 'c');

        if ($handle === false) {
            return null;
        }

        // An unobtainable lock must not fail the request; the attempt simply
        // proceeds with the old, narrower guarantee.
        if (!@flock($handle, LOCK_EX)) {
            @fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * @param resource|null $handle
     */
    private function unlock(mixed $handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    private function path(string $key): string
    {
        return rtrim($this->directory, '/\\')
            . DIRECTORY_SEPARATOR
            . sha1($key)
            . '.json';
    }

    private function report(string $key, Throwable $exception): void
    {
        try {
            $this->logger?->warning('Rate limiter degraded to allow', [
                'key' => $key,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        } catch (Throwable) {
            // Logging must never be the reason a request fails.
        }
    }
}
