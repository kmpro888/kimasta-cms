<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Entity;

/**
 * A validated `theme.json` manifest.
 *
 * Validation happens once at discovery, so the rest of the system can trust these
 * values without re-checking.
 */
final class ThemeManifest
{
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $version,
        public readonly ?string $description,
        public readonly ?string $author,
        public readonly string $directory,
    ) {
    }

    public function basePath(): string
    {
        return $this->directory;
    }

    public function templatesPath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'templates';
    }

    public function partialsPath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'partials';
    }

    public function assetsPath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'assets';
    }

    /**
     * Public URL base for this theme's assets.
     */
    public function assetsUrl(): string
    {
        return '/themes/' . $this->name . '/assets';
    }
}
