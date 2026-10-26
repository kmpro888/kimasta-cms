<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Service;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Contracts\ContentTypeRegistryInterface;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;
use CMS\Modules\Content\Exceptions\ContentNotFoundException;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Media\Exceptions\MediaNotFoundException;
use CMS\Modules\Seo\Contracts\BreadcrumbServiceInterface;
use CMS\Modules\Seo\Contracts\SeoServiceInterface;
use CMS\Modules\Seo\Entity\SeoMeta;
use CMS\Modules\Seo\Repository\SeoRepositoryInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;

/**
 * The single source of SEO truth.
 *
 * Defaults are derived, overrides are stored, and the result is a finished
 * `SeoMeta`. A theme prints `render($meta)` and cannot invent its own canonical or
 * robots value, which is exactly the acceptance criterion.
 *
 * All values are escaped on output. A meta description is user data and is treated
 * as such.
 */
final class SeoService implements SeoServiceInterface
{
    private const DEFAULT_DESCRIPTION_LIMIT = 160;

    private const SCHEMA_MAP = [
        'post' => 'Article',
        'page' => 'WebPage',
    ];

    public function __construct(
        private readonly SeoRepositoryInterface $seo,
        private readonly ContentServiceInterface $contents,
        private readonly ContentTypeRegistryInterface $types,
        private readonly UrlGeneratorInterface $urls,
        private readonly SiteServiceInterface $sites,
        private readonly UserServiceInterface $users,
        private readonly MediaServiceInterface $media,
    ) {
    }

    public function metaFor(int $contentId, int $siteId): SeoMeta
    {
        try {
            $content = $this->contents->get($contentId, $siteId);
        } catch (ContentNotFoundException) {
            throw new ContentNotFoundException();
        }

        $site = $this->sites->findById($siteId);
        $override = $this->seo->find($contentId, $siteId);

        $baseUrl = rtrim($site->baseUrl(), '/');
        $path = $this->urls->pathFor($content->type, $content->slug);

        $title = $this->firstNonEmpty(
            $override['meta_title'] ?? null,
            $content->title
        );

        $description = $this->firstNonEmpty(
            $override['meta_description'] ?? null,
            $content->excerpt,
            $this->truncate((string) $content->contentPlain, self::DEFAULT_DESCRIPTION_LIMIT)
        );

        $canonical = $this->firstNonEmpty(
            $override['canonical_url'] ?? null,
            $baseUrl . $path
        );

        $authorName = '';

        if ($content->authorId > 0) {
            $author = $this->users->find($content->authorId, $siteId);
            $authorName = $author?->displayName ?? '';
        }

        return new SeoMeta(
            title: $title,
            description: $description,
            canonical: $canonical,
            robotsIndex: (bool) ($override['robots_index'] ?? true),
            robotsFollow: (bool) ($override['robots_follow'] ?? true),
            ogTitle: $this->firstNonEmpty($override['og_title'] ?? null, $title),
            ogDescription: $this->firstNonEmpty($override['og_description'] ?? null, $description),
            ogImage: $this->ogImageUrl($override['og_image_id'] ?? null, $siteId, $baseUrl),
            ogType: $content->type === 'page' ? 'website' : 'article',
            schemaType: $this->firstNonEmpty(
                $override['schema_type'] ?? null,
                self::SCHEMA_MAP[$content->type] ?? 'WebPage'
            ),
            siteName: $site->name(),
            locale: $site->defaultLocale(),
            publishedAt: $content->publishedAt?->format(DATE_ATOM),
            modifiedAt: $content->updatedAt->format(DATE_ATOM),
            authorId: $content->authorId > 0 ? $content->authorId : null,
            authorName: $authorName,
        );
    }

    public function store(int $contentId, int $siteId, array $attributes): SeoMeta
    {
        // Confirm the row belongs to this tenant before writing anything.
        $this->contents->get($contentId, $siteId);

        $this->seo->put($contentId, $siteId, [
            'meta_title' => $this->nullableString($attributes['meta_title'] ?? null, 255),
            'meta_description' => $this->nullableString($attributes['meta_description'] ?? null, 320),
            'canonical_url' => $this->nullableString($attributes['canonical_url'] ?? null, 500),
            'robots_index' => (bool) ($attributes['robots_index'] ?? true),
            'robots_follow' => (bool) ($attributes['robots_follow'] ?? true),
            'og_title' => $this->nullableString($attributes['og_title'] ?? null, 255),
            'og_description' => $this->nullableString($attributes['og_description'] ?? null, 320),
            'og_image_id' => isset($attributes['og_image_id']) ? (int) $attributes['og_image_id'] : null,
            'schema_type' => $this->nullableString($attributes['schema_type'] ?? null, 80),
        ]);

        return $this->metaFor($contentId, $siteId);
    }

    public function render(SeoMeta $meta): string
    {
        $tags = [
            '<title>' . $this->e($meta->title) . '</title>',
            '<meta name="description" content="' . $this->e($meta->description) . '">',
            '<link rel="canonical" href="' . $this->e($meta->canonical) . '">',
            '<meta name="robots" content="' . $this->e($meta->robots()) . '">',
            '<meta property="og:type" content="' . $this->e($meta->ogType) . '">',
            '<meta property="og:title" content="' . $this->e($meta->ogTitle) . '">',
            '<meta property="og:description" content="' . $this->e($meta->ogDescription) . '">',
            '<meta property="og:url" content="' . $this->e($meta->canonical) . '">',
            '<meta property="og:site_name" content="' . $this->e($meta->siteName) . '">',
            '<meta property="og:locale" content="' . $this->e($meta->locale) . '">',
            '<meta name="twitter:card" content="' . ($meta->ogImage !== null ? 'summary_large_image' : 'summary') . '">',
            '<meta name="twitter:title" content="' . $this->e($meta->ogTitle) . '">',
            '<meta name="twitter:description" content="' . $this->e($meta->ogDescription) . '">',
        ];

        if ($meta->ogImage !== null && $meta->ogImage !== '') {
            $tags[] = '<meta property="og:image" content="' . $this->e($meta->ogImage) . '">';
            $tags[] = '<meta name="twitter:image" content="' . $this->e($meta->ogImage) . '">';
        }

        return implode("\n", $tags);
    }

    public function renderSchema(SeoMeta $meta): string
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $meta->schemaType,
            'headline' => $meta->title,
            'description' => $meta->description,
            'url' => $meta->canonical,
            'inLanguage' => $meta->locale,
        ];

        if ($meta->publishedAt !== null) {
            $schema['datePublished'] = $meta->publishedAt;
        }

        if ($meta->modifiedAt !== null) {
            $schema['dateModified'] = $meta->modifiedAt;
        }

        if ($meta->authorName !== '') {
            $schema['author'] = [
                '@type' => 'Person',
                'name' => $meta->authorName,
            ];
        }

        if ($meta->ogImage !== null && $meta->ogImage !== '') {
            $schema['image'] = $meta->ogImage;
        }

        $schema['publisher'] = [
            '@type' => 'Organization',
            'name' => $meta->siteName,
        ];

        $encoded = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // JSON-LD inside a script tag: escape the sequence that could close it.
        $encoded = str_replace('</', '<\/', (string) $encoded);

        return '<script type="application/ld+json">' . $encoded . '</script>';
    }

    public function isIndexable(int $contentId, int $siteId): bool
    {
        return $this->metaFor($contentId, $siteId)->isIndexable();
    }

    private function ogImageUrl(mixed $mediaId, int $siteId, string $baseUrl): ?string
    {
        if (!is_numeric($mediaId) || (int) $mediaId <= 0) {
            return null;
        }

        try {
            $media = $this->media->get((int) $mediaId, $siteId);
        } catch (MediaNotFoundException) {
            // A deleted featured image must not break the page.
            return null;
        }

        return rtrim($baseUrl, '/') . '/uploads/' . ltrim($media->path, '/');
    }

    private function firstNonEmpty(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $maxLength);
    }

    private function truncate(string $value, int $limit): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit)) . '…';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
