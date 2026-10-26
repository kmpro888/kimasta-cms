<?php

declare(strict_types=1);

namespace CMS\Modules\Site\Service;

use CMS\Modules\Site\Contracts\SettingsServiceInterface;
use CMS\Modules\Site\Exceptions\SiteNotFoundException;
use CMS\Modules\Site\Repository\SettingsRepositoryInterface;
use CMS\Modules\Site\Repository\SiteRepositoryInterface;
use JsonException;
use RuntimeException;

/**
 * Per-site settings with a two-level cache.
 *
 * Level 1 (per request): an in-process array, so a page that reads the same
 * setting five times costs one lookup.
 *
 * Level 2 (cross request): every autoload setting is compiled into
 * /storage/cache/system/site-settings-<siteId>.php, so a cached public page
 * can read its settings with a single opcache hit and zero queries.
 *
 * Non-autoload keys intentionally fall through to one indexed query: they are
 * rare by definition, and compiling them would bloat the cache file.
 *
 * Every method takes an explicit site id. There is no ambient current site and
 * no default site.
 */
final class SettingsService implements SettingsServiceInterface
{
    /** @var array<int, array<string, array<string, mixed>>> */
    private array $memo = [];

    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly SiteRepositoryInterface $sites,
        private readonly string $cacheDirectory,
    ) {
    }

    public function get(int $siteId, string $key, string $namespace = 'general', mixed $default = null): mixed
    {
        $this->assertSiteExists($siteId);

        $autoloaded = $this->autoloaded($siteId);

        if (array_key_exists($namespace, $autoloaded) && array_key_exists($key, $autoloaded[$namespace])) {
            return $autoloaded[$namespace][$key];
        }

        $value = $this->settings->find($siteId, $namespace, $key);

        return $value ?? $default;
    }

    public function all(int $siteId, string $namespace = 'general'): array
    {
        $this->assertSiteExists($siteId);

        $merged = $this->autoloaded($siteId)[$namespace] ?? [];

        foreach ($this->settings->forNamespace($siteId, $namespace) as $key => $value) {
            $merged[$key] = $value;
        }

        return $merged;
    }

    public function autoloaded(int $siteId): array
    {
        if (isset($this->memo[$siteId])) {
            return $this->memo[$siteId];
        }

        $compiled = $this->readCompiled($siteId);

        if ($compiled !== null) {
            return $this->memo[$siteId] = $compiled;
        }

        $fromDatabase = $this->settings->autoloaded($siteId);
        $this->writeCompiled($siteId, $fromDatabase);

        return $this->memo[$siteId] = $fromDatabase;
    }

    public function set(
        int $siteId,
        string $key,
        mixed $value,
        string $namespace = 'general',
        bool $autoload = false,
    ): void {
        $this->assertSiteExists($siteId);

        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Setting value is not JSON encodable.', 0, $exception);
        }

        $this->settings->put($siteId, $namespace, $key, $encoded, $autoload);
        $this->refresh($siteId);
    }

    public function forget(int $siteId, string $key, string $namespace = 'general'): void
    {
        $this->assertSiteExists($siteId);

        $this->settings->forget($siteId, $namespace, $key);
        $this->refresh($siteId);
    }

    /**
     * Rebuild the compiled autoload cache after a write.
     *
     * The contract says a write "refreshes" the compiled cache, so this must
     * rebuild it rather than merely drop it: dropping alone would leave the next
     * request to repopulate from a query, which is the cost this cache exists to
     * avoid.
     */
    private function refresh(int $siteId): void
    {
        unset($this->memo[$siteId]);
        $this->removeCompiled($siteId);

        $values = $this->settings->autoloaded($siteId);
        $this->memo[$siteId] = $values;
        $this->writeCompiled($siteId, $values);
    }

    public function invalidate(int $siteId): void
    {
        unset($this->memo[$siteId]);
        $this->removeCompiled($siteId);
    }

    private function removeCompiled(int $siteId): void
    {
        $path = $this->pathFor($siteId);

        if (is_file($path)) {
            @unlink($path);
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    public function pathFor(int $siteId): string
    {
        return $this->cacheDirectory . DIRECTORY_SEPARATOR . 'site-settings-' . $siteId . '.php';
    }

    private function assertSiteExists(int $siteId): void
    {
        if ($siteId <= 0 || $this->sites->findById($siteId) === null) {
            throw new SiteNotFoundException();
        }
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function readCompiled(int $siteId): ?array
    {
        $path = $this->pathFor($siteId);

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        /** @var mixed $loaded */
        $loaded = require $path;

        if (!is_array($loaded)) {
            return null;
        }

        /** @var array<string, array<string, mixed>> $loaded */
        return $loaded;
    }

    /**
     * @param array<string, array<string, mixed>> $values
     */
    private function writeCompiled(int $siteId, array $values): void
    {
        if (!is_dir($this->cacheDirectory)
            && !mkdir($this->cacheDirectory, 0775, true)
            && !is_dir($this->cacheDirectory)) {
            // A read-only cache directory must not break a request: the
            // in-process memo still serves the rest of this request.
            return;
        }

        $path = $this->pathFor($siteId);
        $contents = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "/*\n * Compiled autoload settings for site " . $siteId . ". DO NOT EDIT.\n"
            . " * Regenerated by " . self::class . "::set()/forget().\n */\n\n"
            . 'return ' . var_export($values, true) . ";\n";

        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $contents) === false) {
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
}
