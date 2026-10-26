<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Resources;

use CMS\Modules\Theme\Entity\ThemeManifest;

/**
 * Explicit theme projection.
 *
 * The absolute directory is deliberately omitted: a response must not disclose
 * server filesystem layout.
 */
final class ThemeResource implements ResourceInterface
{
    public function __construct(private readonly ?string $activeName = null) {
    }

    /**
     * @param ThemeManifest $subject
     *
     * @return array<string, mixed>
     */
    public function toArray(mixed $subject): array
    {
        if (!$subject instanceof ThemeManifest) {
            throw new \InvalidArgumentException('ThemeResource expects a ThemeManifest.');
        }

        return [
            'name' => $subject->name,
            'title' => $subject->title,
            'version' => $subject->version,
            'description' => $subject->description,
            'author' => $subject->author,
            'assets_url' => $subject->assetsUrl(),
            'active' => $this->activeName !== null && $this->activeName === $subject->name,
        ];
    }

    public static function withActive(?string $activeName): self
    {
        return new self($activeName);
    }
}
