<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Http;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;
use CMS\Modules\Seo\Service\FeedService;
use CMS\Modules\Site\Contracts\SiteResolverInterface;
use CMS\Modules\Site\Exceptions\InvalidHostException;
use CMS\Modules\Site\Exceptions\SiteNotFoundException;

/**
 * `/feed` and `/feed.xml`.
 *
 * Serves the generated artifact. When it is missing — a fresh install, or a site whose cron has not
 * run — it is generated once and then served, so a feed URL never answers 404 for a site that has
 * articles.
 */
final class FeedController implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteResolverInterface $sites,
        private readonly FeedService $feed,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $siteId = $this->sites->siteIdFor($request->host());
        } catch (SiteNotFoundException|InvalidHostException) {
            throw HttpException::notFound();
        }

        $xml = $this->feed->read($siteId);

        if ($xml === null) {
            $this->feed->generate($siteId);
            $xml = $this->feed->read($siteId);
        }

        if ($xml === null) {
            throw HttpException::notFound();
        }

        return new Response($xml, 200, [
            'Content-Type' => 'application/rss+xml; charset=utf-8',
            // Cron rebuilds it; a quarter of an hour is a safe revalidation window for a reader.
            'Cache-Control' => 'public, max-age=900',
        ]);
    }
}
