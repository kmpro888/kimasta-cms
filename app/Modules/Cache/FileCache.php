<?php

declare(strict_types=1);

namespace CMS\Modules\Cache;

use CMS\Shared\Contracts\CacheInterface;

/**
 * Filesystem object cache implementing the frozen CacheInterface.
 *
 * Two levels, because they solve different problems:
 *
 *  - **Request memo (L1)**: a plain array. Reading the same setting or site twice
 *    in one request costs nothing after the first read.
 *  - **File cache (L2)**: survives the request. Values are serialized with an
 *    expiry header and written atomically, so a concurrent reader never sees a
 *    half-written entry.
 *
 * A corrupt or unreadable entry is treated as a miss and deleted, never as an
 * error: a broken cache must degrade to "slower", not to "down".
 */
final class FileCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        private readonly string $directory,
        private readonly int $defaultTtl = 3600,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $entry = $this->read($key);

        if ($entry === null) {
            return $default;
        }

        return $this->memo[$key] = $entry['value'];
    }

    public function has(string $key): bool
    {
        if (array_key_exists($key, $this->memo)) {
            return true;
        }

        return $this->read($key) !== null;
    }

    public function put(string $key, mixed $value, ?int $ttl = null): void
    {
        $this->memo[$key] = $value;

        $expiresAt = $ttl === null ? null : time() + max(0, $ttl);

        $this->write($key, ['value' => $value, 'expires_at' => $expiresAt]);
    }

    public function forever(string $key, mixed $value): void
    {
        $this->memo[$key] = $value;
        $this->write($key, ['value' => $value, 'expires_at' => null]);
    }

    public function forget(string $key): void
    {
        unset($this->memo[$key]);

        $path = $this->pathFor($key);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function flush(): void
    {
        $this->memo = [];

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.cache') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Drop only the in-process memo, leaving files intact.
     */
    public function flushRequest(): void
    {
        $this->memo = [];
    }

    /**
     * @return array{value: mixed, expires_at: int|null}|null
     */
    private function read(string $key): ?array
    {
        $path = $this->pathFor($key);

        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false || $raw === '') {
            @unlink($path);

            return null;
        }

        $separator = strpos($raw, "\n");

        if ($separator === false) {
            // Corrupt: no header line.
            @unlink($path);

            return null;
        }

        $header = json_decode(substr($raw, 0, $separator), true);

        if (!is_array($header) || !array_key_exists('expires_at', $header)) {
            @unlink($path);

            return null;
        }

        $expiresAt = $header['expires_at'];

        if ($expiresAt !== null && (!is_int($expiresAt) || time() >= $expiresAt)) {
            @unlink($path);

            return null;
        }

        /** @var mixed $value */
        $value = @unserialize(substr($raw, $separator + 1), ['allowed_classes' => true]);

        if ($value === false && substr($raw, $separator + 1) !== serialize(false)) {
            @unlink($path);

            return null;
        }

        return ['value' => $value, 'expires_at' => $expiresAt];
    }

    /**
     * @param array{value: mixed, expires_at: int|null} $entry
     */
    private function write(string $key, array $entry): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            // A read-only cache directory must not break a request; the memo
            // still serves the rest of this request.
            return;
        }

        $payload = json_encode(['expires_at' => $entry['expires_at']]) . "\n" . serialize($entry['value']);
        $path = $this->pathFor($key);
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $payload) === false) {
            return;
        }

        @chmod($temp, 0644);

        if (!@rename($temp, $path)) {
            @unlink($temp);

            return;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    private function pathFor(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . sha1($key) . '.cache';
    }
}
