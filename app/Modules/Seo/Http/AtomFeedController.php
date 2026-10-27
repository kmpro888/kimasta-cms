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
 * The Atom feed.
 *
 * A controller of its own rather than a method on `FeedController`: a route whose handler is a class
 * name only ever gets `handle()` called, so a second action on the same class is unreachable — the
 * first version of this served RSS at `/atom.xml` for exactly that reason, and the content-type
 * assertion in the test caught it.
 */
final class AtomFeedController implements RequestHandlerInterface
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

        $xml = $this->feed->readAtom($siteId);

        if ($xml === null) {
            $this->feed->generateAtom($siteId);
            $xml = $this->feed->readAtom($siteId);
        }

        if ($xml === null) {
            throw HttpException::notFound();
        }

        return new Response($xml, 200, [
            'Content-Type' => 'application/atom+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }
}
