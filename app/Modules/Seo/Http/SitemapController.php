<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Http;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;
use CMS\Modules\Seo\Contracts\SitemapServiceInterface;
use CMS\Modules\Site\Contracts\SiteResolverInterface;
use CMS\Modules\Site\Exceptions\InvalidHostException;
use CMS\Modules\Site\Exceptions\SiteNotFoundException;

/**
 * Serves the generated sitemap.
 *
 * The artifact is written by cron, and serving it is a file read. It is deliberately NOT
 * generated on demand: a crawler hitting this URL is exactly the traffic a content scan
 * must not be triggered by, which is the whole reason the sitemap is a generated file
 * rather than a live query.
 *
 * An absent artifact is a 404, not an empty sitemap. An empty `<urlset>` is a valid answer
 * that tells a crawler the site has no pages, and that is worse than "not ready yet".
 */
final class SitemapController implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteResolverInterface $sites,
        private readonly SitemapServiceInterface $sitemaps,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $siteId = $this->sites->siteIdFor($request->host());
        } catch (SiteNotFoundException|InvalidHostException) {
            throw HttpException::notFound();
        }

        $xml = $this->sitemaps->read($siteId);

        if ($xml === null) {
            throw HttpException::notFound();
        }

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            // Crawlers may cache a sitemap; the artifact is rebuilt by cron, so a day is a
            // safe revalidation window.
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
