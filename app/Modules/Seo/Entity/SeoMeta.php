<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Entity;

/**
 * Fully resolved SEO metadata for one page.
 *
 * Every value here is final: a theme renders this and does not recompute it. That
 * is what prevents two sources of canonical truth.
 */
final class SeoMeta
{
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $canonical,
        public readonly bool $robotsIndex,
        public readonly bool $robotsFollow,
        public readonly string $ogTitle,
        public readonly string $ogDescription,
        public readonly ?string $ogImage,
        public readonly string $ogType,
        public readonly string $schemaType,
        public readonly string $siteName,
        public readonly string $locale,
        public readonly ?string $publishedAt,
        public readonly ?string $modifiedAt,
        public readonly ?int $authorId,
        public readonly string $authorName,
    ) {
    }

    /**
     * The robots meta value, e.g. `index,follow` or `noindex,nofollow`.
     */
    public function robots(): string
    {
        return ($this->robotsIndex ? 'index' : 'noindex')
            . ',' . ($this->robotsFollow ? 'follow' : 'nofollow');
    }

    public function isIndexable(): bool
    {
        return $this->robotsIndex;
    }
}
