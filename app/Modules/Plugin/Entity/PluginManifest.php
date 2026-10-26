<?php

declare(strict_types=1);

namespace CMS\Modules\Plugin\Entity;

/**
 * A validated `plugin.json` manifest.
 *
 * Validation happens once at discovery, so the rest of the platform can trust these
 * values without re-checking. A manifest that fails validation means the plugin is
 * simply not a plugin — it is skipped, never fatal, because one broken plugin must not
 * take the site down.
 */
final class PluginManifest
{
    /**
     * @param list<string> $requiresPlugins
     * @param array<string, mixed> $requires
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $author,
        public readonly string $entryClass,
        public readonly string $directory,
        public readonly array $requiresPlugins = [],
        public readonly array $requires = [],
    ) {
    }

    public function entryFile(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'Plugin.php';
    }

    public function migrationsPath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'migrations';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'title' => $this->title,
            'description' => $this->description,
            'author' => $this->author,
            'entry_class' => $this->entryClass,
            'requires_plugins' => $this->requiresPlugins,
            'requires' => $this->requires,
        ];
    }
}
