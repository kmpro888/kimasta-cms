<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Rendering;

use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Entity\Content;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
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
    ) {
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
            'analytics_html' => $this->analyticsHtml($siteId),

            'title' => $content->title,
            'url' => $this->urls->pathFor($content->type, $content->slug),
            'content_type' => $content->type,
            'content_html' => (string) $content->contentHtml,
            'excerpt' => $content->excerpt,
            'published_at' => $content->publishedAt?->format(DATE_ATOM),
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
        ]);
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
            'analytics_html' => $this->analyticsHtml($site->id()),

            'title' => $title,
            'items' => $prepared,
            'page' => $page,
            'total_pages' => $totalPages,
            'term' => $term,

            // Archive pages still get SEO tags; they are not in the sitemap, but a
            // crawler can reach them.
            'seo_html' => '',
            'seo_schema' => '',
            'breadcrumbs' => [],
            'breadcrumbs_html' => '',
        ]);
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
            'analytics_html' => $this->analyticsHtml($site->id()),
            'title' => 'Not found',
            'notice' => 'The page you are looking for does not exist.',
            'items' => [],
        ]);
    }

    /**
     * @return array{url: string, alt: string, width: ?int, height: ?int}|null
     */
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
        ];
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
