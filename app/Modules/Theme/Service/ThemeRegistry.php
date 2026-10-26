<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Service;

use CMS\Core\Events\DomainEvent;
use CMS\Modules\Site\Repository\SiteRepositoryInterface;
use CMS\Modules\Theme\Contracts\ThemeRegistryInterface;
use CMS\Modules\Theme\Entity\ThemeManifest;
use CMS\Modules\Theme\Exceptions\InvalidThemeManifestException;
use CMS\Modules\Theme\Exceptions\ThemeNotFoundException;
use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;

/**
 * Theme discovery and activation.
 *
 * Discovery reads `theme.json` for every directory under the themes root and
 * validates it. A directory without a valid manifest is simply not a theme —
 * reported, never fatal, so one broken theme cannot take the site down.
 *
 * Activation writes the site's `active_theme` through the Site module's published
 * repository contract, and emits `theme.activated` so the cache layer can evict
 * everything that declared the old theme as a dependency.
 */
final class ThemeRegistry implements ThemeRegistryInterface
{
    /** Name of the theme used when a site has none assigned. */
    public const FALLBACK = 'default';

    /** @var array<string, ThemeManifest>|null */
    private ?array $discovered = null;

    public function __construct(
        private readonly string $themesRoot,
        private readonly SiteRepositoryInterface $sites,
        private readonly EventDispatcherInterface $events,
        private readonly ClockInterface $clock,
    ) {
    }

    public function all(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $themes = [];

        if (!is_dir($this->themesRoot)) {
            return $this->discovered = [];
        }

        foreach (scandir($this->themesRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $directory = $this->themesRoot . DIRECTORY_SEPARATOR . $entry;

            if (!is_dir($directory)) {
                continue;
            }

            $manifest = $this->readManifest($directory, $entry);

            if ($manifest !== null) {
                $themes[$manifest->name] = $manifest;
            }
        }

        ksort($themes);

        return $this->discovered = $themes;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function get(string $name): ThemeManifest
    {
        $themes = $this->all();

        if (!isset($themes[$name])) {
            throw new ThemeNotFoundException($name);
        }

        return $themes[$name];
    }

    public function activeFor(int $siteId): ThemeManifest
    {
        // Read the assigned theme from the Site module's published repository
        // contract. The frozen SiteContextInterface does not expose `active_theme`,
        // and widening it for one display field would be the wrong trade.
        $site = $this->sites->findById($siteId);
        $assigned = $site?->activeTheme();

        if (is_string($assigned) && $assigned !== '' && $this->has($assigned)) {
            return $this->get($assigned);
        }

        // Fall back to the default theme rather than failing to render at all.
        if ($this->has(self::FALLBACK)) {
            return $this->get(self::FALLBACK);
        }

        $themes = $this->all();

        if ($themes === []) {
            throw new ThemeNotFoundException();
        }

        return reset($themes);
    }

    public function activate(int $siteId, string $name): ThemeManifest
    {
        $manifest = $this->get($name);

        $previous = null;

        try {
            $previous = $this->activeFor($siteId)->name;
        } catch (ThemeNotFoundException) {
            $previous = null;
        }

        $this->sites->updateTheme($siteId, $name);

        $this->events->dispatch(DomainEvent::create(
            'theme.activated',
            [
                'site_id' => $siteId,
                'theme' => $name,
                'previous_theme' => $previous,
            ],
            $siteId,
            $this->clock,
        ));

        return $manifest;
    }

    public function pathFor(string $name): string
    {
        return $this->get($name)->basePath();
    }

    /**
     * Read and validate a theme manifest.
     *
     * Returns null for anything that is not a usable theme: an invalid theme is a
     * discovery miss, not a crash.
     */
    private function readManifest(string $directory, string $entry): ?ThemeManifest
    {
        $manifestPath = $directory . DIRECTORY_SEPARATOR . 'theme.json';

        if (!is_file($manifestPath)) {
            return null;
        }

        $raw = @file_get_contents($manifestPath);

        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        try {
            return $this->validate($decoded, $entry, $directory);
        } catch (InvalidThemeManifestException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validate(array $data, string $entry, string $directory): ThemeManifest
    {
        $name = isset($data['name']) && is_string($data['name']) && $data['name'] !== ''
            ? $data['name']
            : $entry;

        // A manifest name that disagrees with its directory is a sign of a copied
        // theme; trust the directory so the filesystem stays the source of truth.
        if ($name !== $entry) {
            $name = $entry;
        }

        $version = isset($data['version']) && is_string($data['version']) ? $data['version'] : '';

        if ($version === '') {
            throw new InvalidThemeManifestException('Theme manifest is missing a version: ' . $name);
        }

        return new ThemeManifest(
            name: $name,
            title: isset($data['title']) && is_string($data['title']) ? $data['title'] : ucfirst($name),
            version: $version,
            description: isset($data['description']) && is_string($data['description']) ? $data['description'] : null,
            author: isset($data['author']) && is_string($data['author']) ? $data['author'] : null,
            directory: $directory,
        );
    }
}
