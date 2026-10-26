<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Entity;

/**
 * What kind of page is being rendered.
 *
 * Drives template resolution. Kept as a small value object rather than a string so
 * an unknown kind fails at construction instead of silently falling back to the
 * wrong template.
 */
final class TemplateContext
{
    public const SINGLE = 'single';
    public const PAGE = 'page';
    public const ARCHIVE = 'archive';
    public const TAXONOMY = 'taxonomy';
    public const AUTHOR = 'author';
    public const SEARCH = 'search';
    public const HOME = 'home';
    public const NOT_FOUND = '404';

    private const KINDS = [
        self::SINGLE,
        self::PAGE,
        self::ARCHIVE,
        self::TAXONOMY,
        self::AUTHOR,
        self::SEARCH,
        self::HOME,
        self::NOT_FOUND,
    ];

    public function __construct(
        public readonly string $kind,
        public readonly ?string $contentType = null,
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown template context kind: ' . $kind);
        }
    }

    public static function single(string $contentType = 'post'): self
    {
        return new self(self::SINGLE, $contentType);
    }

    public static function archive(string $contentType = 'post'): self
    {
        return new self(self::ARCHIVE, $contentType);
    }

    public static function page(): self
    {
        return new self(self::PAGE);
    }

    public static function home(): self
    {
        return new self(self::HOME);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }
}
