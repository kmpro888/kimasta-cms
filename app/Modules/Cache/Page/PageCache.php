<?php

declare(strict_types=1);

namespace CMS\Modules\Cache\Page;

use CMS\Modules\Cache\Contracts\PageCacheInterface;
use CMS\Modules\Cache\Entity\CacheDependency;
use CMS\Modules\Cache\Entity\CachedPage;

/**
 * Filesystem full-page cache, namespaced per site.
 *
 * Layout:
 *
 *   {root}/{siteId}/pages/{sha1(path)}.html   entry: JSON header line + HTML body
 *   {root}/{siteId}/deps.php                  dependency index: depKey => [path, …]
 *   {root}/{siteId}/locks/                    stampede locks
 *
 * A HIT costs exactly one file read and zero database queries. The dependency
 * index is read only during invalidation, which is a rare write path — so the hot
 * path never pays for it.
 *
 * A corrupt entry is treated as a miss and deleted. A cache that cannot be read
 * must slow the site down, never break it.
 */
final class PageCache implements PageCacheInterface
{
    /** @var array<string, array<string, true>> */
    private array $dependencyIndexMemo = [];

    public function __construct(
        private readonly string $root,
        private readonly int $defaultTtl = 0,
    ) {
    }

    public function get(int $siteId, string $path): ?CachedPage
    {
        $file = $this->entryPath($siteId, $path);

        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);

        if ($raw === false || $raw === '') {
            @unlink($file);

            return null;
        }

        $separator = strpos($raw, "\n");

        if ($separator === false) {
            @unlink($file);

            return null;
        }

        $header = json_decode(substr($raw, 0, $separator), true);

        if (!is_array($header) || !array_key_exists('stored_at', $header)) {
            @unlink($file);

            return null;
        }

        $expiresAt = $header['expires_at'] ?? null;
        $storedAt = (int) $header['stored_at'];

        if ($expiresAt !== null && (!is_int($expiresAt) || time() >= $expiresAt)) {
            @unlink($file);

            return null;
        }

        /** @var list<string> $dependencies */
        $dependencies = is_array($header['dependencies'] ?? null) ? $header['dependencies'] : [];

        return new CachedPage(
            html: substr($raw, $separator + 1),
            dependencies: $dependencies,
            storedAt: $storedAt,
            expiresAt: is_int($expiresAt) ? $expiresAt : null,
        );
    }

    public function put(int $siteId, string $path, string $html, array $dependencies = [], ?int $ttl = null): void
    {
        $directory = $this->pagesDirectory($siteId);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $ttl = $ttl ?? ($this->defaultTtl > 0 ? $this->defaultTtl : null);
        $now = time();

        $dependencyKeys = [];

        foreach ($dependencies as $dependency) {
            if ($dependency instanceof CacheDependency) {
                $dependencyKeys[] = $dependency->key();
            } elseif ($dependency instanceof \CMS\Modules\Cache\Contracts\CacheDependencyInterface) {
                $dependencyKeys[] = $dependency->key();
            }
        }

        $header = [
            'site_id' => $siteId,
            'path' => $path,
            'stored_at' => $now,
            'expires_at' => $ttl === null ? null : $now + max(0, $ttl),
            'dependencies' => $dependencyKeys,
        ];

        // A previous entry may have declared different dependencies; drop its old
        // registrations before writing the new ones.
        $this->unregisterDependencies($siteId, $path);

        $payload = json_encode($header, JSON_UNESCAPED_SLASHES) . "\n" . $html;
        $file = $this->entryPath($siteId, $path);
        $temp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $payload) === false) {
            return;
        }

        @chmod($temp, 0644);

        if (!@rename($temp, $file)) {
            @unlink($temp);

            return;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        foreach ($dependencyKeys as $key) {
            $this->registerDependency($siteId, $key, $path);
        }
    }

    public function forget(int $siteId, string $path): void
    {
        $this->unregisterDependencies($siteId, $path);

        $file = $this->entryPath($siteId, $path);

        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function forgetByDependency(int $siteId, string $dependencyKey): int
    {
        $index = $this->loadIndex($siteId);
        $paths = $index[$dependencyKey] ?? [];

        if ($paths === []) {
            return 0;
        }

        $removed = 0;

        foreach (array_keys($paths) as $path) {
            $file = $this->entryPath($siteId, (string) $path);

            if (is_file($file)) {
                @unlink($file);
                $removed++;
            }

            // Remove this path from every other dependency's list too.
            foreach ($index as $key => $entries) {
                if (isset($entries[$path])) {
                    unset($index[$key][$path]);

                    if ($index[$key] === []) {
                        unset($index[$key]);
                    }
                }
            }
        }

        unset($index[$dependencyKey]);

        $this->saveIndex($siteId, $index);

        return $removed;
    }

    public function flushSite(int $siteId): int
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . $siteId;

        if (!is_dir($directory)) {
            return 0;
        }

        $removed = 0;

        foreach (glob($directory . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . '*.html') ?: [] as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        unset($this->dependencyIndexMemo[$this->indexKey($siteId)]);

        $indexFile = $this->indexPath($siteId);

        if (is_file($indexFile)) {
            @unlink($indexFile);
        }

        return $removed;
    }

    public function isEligible(string $method, bool $authenticated, bool $isPreview, bool $isPublic): bool
    {
        $method = strtoupper($method);

        // Only safe, side-effect-free methods; only anonymous; only public
        // responses; never a preview of unpublished work.
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        if ($authenticated || $isPreview) {
            return false;
        }

        return $isPublic;
    }

    public function keyFor(int $siteId, string $path): string
    {
        return $siteId . ':' . $this->normalizePath($path);
    }

    /**
     * Directory containing page entries for a site.
     */
    public function pagesDirectory(int $siteId): string
    {
        return $this->root . DIRECTORY_SEPARATOR . $siteId . DIRECTORY_SEPARATOR . 'pages';
    }

    public function locksDirectory(int $siteId): string
    {
        return $this->root . DIRECTORY_SEPARATOR . $siteId . DIRECTORY_SEPARATOR . 'locks';
    }

    public function indexPath(int $siteId): string
    {
        return $this->root . DIRECTORY_SEPARATOR . $siteId . DIRECTORY_SEPARATOR . 'deps.php';
    }

    /**
     * @return array<string, array<string, true>>
     */
    public function loadIndex(int $siteId): array
    {
        $memoKey = $this->indexKey($siteId);

        if (isset($this->dependencyIndexMemo[$memoKey])) {
            return $this->dependencyIndexMemo[$memoKey];
        }

        $file = $this->indexPath($siteId);

        if (!is_file($file)) {
            return $this->dependencyIndexMemo[$memoKey] = [];
        }

        /** @var mixed $loaded */
        $loaded = require $file;

        return $this->dependencyIndexMemo[$memoKey] = is_array($loaded) ? $loaded : [];
    }

    /**
     * @param array<string, array<string, true>> $index
     */
    public function saveIndex(int $siteId, array $index): void
    {
        $this->dependencyIndexMemo[$this->indexKey($siteId)] = $index;

        $file = $this->indexPath($siteId);
        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $temp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, "<?php\n\nreturn " . var_export($index, true) . ";\n") === false) {
            return;
        }

        @chmod($temp, 0644);

        if (!@rename($temp, $file)) {
            @unlink($temp);

            return;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    private function entryPath(int $siteId, string $path): string
    {
        return $this->pagesDirectory($siteId)
            . DIRECTORY_SEPARATOR . sha1($this->normalizePath($path)) . '.html';
    }

    private function registerDependency(int $siteId, string $dependencyKey, string $path): void
    {
        $index = $this->loadIndex($siteId);
        $index[$dependencyKey][$this->normalizePath($path)] = true;
        $this->saveIndex($siteId, $index);
    }

    private function unregisterDependencies(int $siteId, string $path): void
    {
        $index = $this->loadIndex($siteId);
        $normalized = $this->normalizePath($path);
        $changed = false;

        foreach ($index as $key => $entries) {
            if (isset($entries[$normalized])) {
                unset($index[$key][$normalized]);
                $changed = true;

                if ($index[$key] === []) {
                    unset($index[$key]);
                }
            }
        }

        if ($changed) {
            $this->saveIndex($siteId, $index);
        }
    }

    private function indexKey(int $siteId): string
    {
        return 'site:' . $siteId;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/';
        }

        $path = (string) preg_replace('/[?#].*$/', '', $path);
        $path = '/' . ltrim($path, '/');
        $path = (string) preg_replace('#/+#', '/', $path);

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}
