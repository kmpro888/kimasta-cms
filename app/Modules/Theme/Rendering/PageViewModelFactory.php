<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Rendering;

use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Entity\Content;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Media\Entity\Media;
use CMS\Modules\Media\Exceptions\MediaNotFoundException;
use CMS\Modules\Seo\Contracts\BreadcrumbServiceInterface;
use CMS\Modules\Seo\Contracts\SeoServiceInterface;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use CMS\Modules\Theme\Entity\TemplateContext;
use CMS\Modules\Theme\Entity\ThemeManifest;
use CMS\Modules\Theme\Entity\ViewModel;
use CMS\Shared\Contracts\SiteContextInterface;
use DateTimeImmutable;
use Throwable;

/**
 * Builds the ViewModel a template renders.
 *
 * This is the boundary the whole theme design rests on: **all** data access
 * happens here, before rendering, so the template receives finished values and
 * cannot reach a database.
 *
 * A failure in one optional section (a deleted featured image, a taxonomy lookup)
 * degrades that section rather than the page. A missing related term must not
 * produce a 500 on an otherwise healthy article.
 */
final class PageViewModelFactory
{
    public function __construct(
        private readonly ContentQueryServiceInterface $query,
        private readonly UrlGeneratorInterface $urls,
        private readonly SeoServiceInterface $seo,
        private readonly BreadcrumbServiceInterface $breadcrumbs,
        private readonly TaxonomyServiceInterface $taxonomy,
        private readonly MediaServiceInterface $media,
        private readonly ?\CMS\Modules\Advertising\Contracts\AdvertisingServiceInterface $advertising = null,
        private readonly ?\CMS\Modules\Analytics\Contracts\AnalyticsServiceInterface $analytics = null,
        private readonly ?\CMS\Modules\Comment\Contracts\CommentServiceInterface $comments = null,
        // Optional for the same reason as the three above: a site without media variants must still
        // render its articles, just without a srcset.
        private readonly ?\CMS\Modules\Media\Contracts\MediaVariantQueryInterface $variants = null,
    ) {
    }

    /**
     * Approved comments for one article.
     *
     * Optional dependency, like analytics and advertising: a site with the comment module removed
     * must render its articles, not fail them. An approved comment existed in the database for
     * weeks while the article page showed nothing, because nothing asked for the thread.
     *
     * @return list<\CMS\Modules\Comment\Entity\Comment>
     */
    private function commentThread(int $siteId, int $contentId): array
    {
        if ($this->comments === null) {
            return [];
        }

        try {
            return $this->comments->thread($siteId, $contentId);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The site analytics snippet, or an empty string.
     *
     * The snippet is printed into every page, so it is also the most likely place for an
     * optional dependency to fail. A missing or broken analytics service must cost the
     * snippet, never the page.
     */
    private function analyticsHtml(int $siteId): string
    {
        if ($this->analytics === null) {
            return '';
        }

        try {
            return $this->analytics->render($siteId);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Rendered ad slots for this page.
     *
     * A placement the theme does not render costs nothing: only the slots a page actually
     * asks for are resolved, and the whole set is one repository read.
     *
     * @param list<int> $termIds
     *
     * @return array<string, string>
     */
    private function adsFor(int $siteId, string $contentType, array $termIds = []): array
    {
        if ($this->advertising === null) {
            return [];
        }

        try {
            return $this->advertising->renderPlacements(
                $siteId,
                ['header', 'sidebar', 'before_article', 'middle_article', 'after_article', 'sticky', 'footer'],
                ['content_type' => $contentType, 'term_ids' => $termIds],
            );
        } catch (\Throwable) {
            // An advertising problem must never take the page down. An empty slot is the
            // correct degradation.
            return [];
        }
    }

    /**
     * ViewModel for a single content row.
     */
    public function forContent(
        SiteContextInterface $site,
        Content $content,
        TemplateContext $context,
        ThemeManifest $theme,
    ): ViewModel {
        $siteId = $site->id();
        $contentId = (int) $content->id;

        $seo = $this->seo->metaFor($contentId, $siteId);
        $crumbs = $this->breadcrumbs->forContent($contentId, $siteId);

        // Resolved once: the template renders the terms and the ad request targets them.
        $terms = $this->termsFor($contentId, $siteId);

        return new ViewModel([
            'kind' => 'single',
            'site_name' => $site->name(),
            'locale' => $site->defaultLocale(),
            'base_url' => $site->baseUrl(),
            'theme_name' => $theme->name,
            'theme_assets' => $theme->assetsUrl(),
            'theme_version' => $this->assetVersion($theme),
            'analytics_html' => $this->analyticsHtml($siteId),

            'title' => $content->title,
            'url' => $this->urls->pathFor($content->type, $content->slug),
            'content_type' => $content->type,
            'content_html' => (string) $content->contentHtml,
            'excerpt' => $content->excerpt,
            'published_at' => $content->publishedAt?->format(DATE_ATOM),
            // A machine value and a human value are different things: the `datetime` attribute
            // needs ISO, the reader needs "24 Oct 2026". Printing one in both places put
            // "2026-10-24T12:21:47+00:00" in front of the reader.
            'published_display' => $content->publishedAt?->format('j M Y'),
            'updated_at' => $content->updatedAt->format(DATE_ATOM),

            'seo_html' => $this->seo->render($seo),
            'seo_schema' => $this->seo->renderSchema($seo),
            'breadcrumbs' => $crumbs,
            'breadcrumbs_html' => $this->breadcrumbs->render($crumbs),
            'breadcrumbs_schema' => $this->breadcrumbs->renderSchema($crumbs),

            'featured_image' => $this->featuredImage($content, $siteId, $site->baseUrl()),
            'terms' => $terms,
            'context' => $context,

            // Ad slots, already selected and rendered. A template prints
            // `ads.before_article` and never queries for an ad, which is what keeps
            // "theme must not query the database" true.
            'ads' => $this->adsFor($siteId, $content->type, array_column($terms, 'id')),

            // Approved comments, flat with their depth so the template can indent without
            // walking a tree. The thread service already excludes pending and spam.
            'comments' => $this->commentThread($siteId, (int) $content->id),
            'comments_open' => $content->commentStatus === 'open',
            // What the comment form posts back. Passed explicitly so the template does not have to
            // reach into a content entity it is otherwise not given.
            'comment_content_id' => (int) $content->id,
        ], new \CMS\Core\I18n\Translator($site->defaultLocale()));
    }

    /**
     * ViewModel for a listing page.
     *
     * @param list<Content>|list<object> $items Summary objects from the query service.
     */
    public function forListing(
        SiteContextInterface $site,
        ThemeManifest $theme,
        string $title,
        array $items,
        int $page = 1,
        int $totalPages = 1,
        ?array $term = null,
    ): ViewModel {
        $prepared = [];

        foreach ($items as $item) {
            $prepared[] = [
                'id' => $item->id,
                'title' => $item->title,
                'url' => $this->urls->pathFor($item->type, $item->slug),
                'excerpt' => $item->excerpt,
                'published_at' => $item->publishedAt?->format(DATE_ATOM),
            ];
        }

        return new ViewModel([
            'kind' => 'archive',
            'site_name' => $site->name(),
            'locale' => $site->defaultLocale(),
            'base_url' => $site->baseUrl(),
            'theme_name' => $theme->name,
            'theme_assets' => $theme->assetsUrl(),
            'theme_version' => $this->assetVersion($theme),
            'analytics_html' => $this->analyticsHtml($site->id()),

            'title' => $title,
            'items' => $prepared,
            'page' => $page,
            'total_pages' => $totalPages,
            'term' => $term,

            // Header and footer slots belong to the page, not to an article, so a listing needs
            // them too. Without this the theme's slots existed but never had anything to print here.
            // \ is an array here, not an entity: the id is read from it defensively so a
            // malformed term cannot turn a listing into a warning.
            'ads' => $this->adsFor(
                $site->id(),
                'listing',
                isset($term['id']) ? [(int) $term['id']] : [],
            ),

            // Archive pages still get SEO tags; they are not in the sitemap, but a
            // crawler can reach them.
            'seo_html' => '',
            'seo_schema' => '',
            'breadcrumbs' => [],
            'breadcrumbs_html' => '',
        ], new \CMS\Core\I18n\Translator($site->defaultLocale()));
    }

    /**
     * ViewModel for the 404 page.
     */
    public function forNotFound(SiteContextInterface $site, ThemeManifest $theme): ViewModel
    {
        return new ViewModel([
            'kind' => '404',
            'site_name' => $site->name(),
            'locale' => $site->defaultLocale(),
            'base_url' => $site->baseUrl(),
            'theme_name' => $theme->name,
            'theme_assets' => $theme->assetsUrl(),
            'theme_version' => $this->assetVersion($theme),
            'analytics_html' => $this->analyticsHtml($site->id()),
            'title' => 'Not found',
            'notice' => 'The page you are looking for does not exist.',
            'items' => [],
        ], new \CMS\Core\I18n\Translator($site->defaultLocale()));
    }

    /**
     * @return array{url: string, alt: string, width: ?int, height: ?int}|null
     */
    /**
     * A cache-busting value for the theme's assets.
     *
     * The stylesheet is served with `max-age=3600`, so without this an operator who edits the
     * theme would spend up to an hour wondering why nothing changed. The file's modification
     * time changes whenever the file does, which is exactly the signal needed.
     */
    private function assetVersion(\CMS\Modules\Theme\Entity\ThemeManifest $theme): int
    {
        $css = $theme->assetsPath() . DIRECTORY_SEPARATOR . 'theme.css';
        $modified = is_file($css) ? filemtime($css) : false;

        return $modified === false ? 1 : (int) $modified;
    }

    private function featuredImage(Content $content, int $siteId, string $baseUrl): ?array
    {
        if ($content->featuredMediaId === null || $content->featuredMediaId <= 0) {
            return null;
        }

        try {
            $media = $this->media->get($content->featuredMediaId, $siteId);
        } catch (MediaNotFoundException) {
            return null;
        } catch (Throwable) {
            return null;
        }

        return [
            'url' => rtrim($baseUrl, '/') . '/uploads/' . ltrim($media->path, '/'),
            'alt' => $media->altText ?? '',
            'width' => $media->width,
            'height' => $media->height,
            'srcset' => $this->srcsetFor($media, $baseUrl),
        ];
    }

    /**
     * A `srcset` for the featured image, or an empty string when there is nothing to choose from.
     *
     * The upload pipeline has been producing thumbnail/medium/large variants all along; nothing ever
     * printed them, so a phone downloaded the full-size file. Only variants in the **original's
     * format** go in: a srcset is a list of candidates for one image, and mixing a WebP into a PNG
     * list is a format switch disguised as a size switch.
     *
     * Widths must differ — three variants of a 24-pixel probe image are one candidate, not three.
     */
    private function srcsetFor(Media $media, string $baseUrl): string
    {
        // The variant reader and the media service are the same object by construction, so falling
        // back to `$media` is exact rather than a guess -- and it keeps working when a container
        // hands this factory only the frozen service.
        $reader = $this->variants
            ?? ($this->media instanceof \CMS\Modules\Media\Contracts\MediaVariantQueryInterface ? $this->media : null);

        if ($reader === null || $media->width === null) {
            return '';
        }

        try {
            $variants = $reader->variantsFor((int) $media->id, (int) $media->siteId);
        } catch (Throwable) {
            return '';
        }

        $candidates = [];
        $seenWidths = [];

        foreach ($variants as $variant) {
            if ($variant->width === null || $variant->width <= 0) {
                continue;
            }

            if ($variant->format !== $media->extension) {
                continue;
            }

            if (isset($seenWidths[$variant->width])) {
                continue;
            }

            $seenWidths[$variant->width] = true;
            $candidates[$variant->width] = $variant->url($baseUrl);
        }

        // The original is the largest candidate, and only useful if it is larger than the biggest
        // variant; otherwise it is a duplicate width.
        if ($media->width !== null && !isset($seenWidths[$media->width])) {
            $candidates[$media->width] = rtrim($baseUrl, '/') . '/uploads/' . ltrim($media->path, '/');
        }

        if (count($candidates) < 2) {
            return '';
        }

        ksort($candidates);

        $parts = [];

        foreach ($candidates as $width => $url) {
            $parts[] = $url . ' ' . $width . 'w';
        }

        return implode(', ', $parts);
    }

    /**
     * @return list<array{id: int, name: string, slug: string, url: string}>
     */
    private function termsFor(int $contentId, int $siteId): array
    {
        try {
            $grouped = $this->taxonomy->termsForContents($siteId, [$contentId]);
        } catch (Throwable) {
            // A taxonomy problem must not break an article page.
            return [];
        }

        $prepared = [];

        foreach ($grouped[$contentId] ?? [] as $term) {
            $prepared[] = [
                'id' => (int) $term->id,
                'name' => $term->name,
                'slug' => $term->slug,
                'url' => '/term/' . $term->slug,
            ];
        }

        return $prepared;
    }
}
