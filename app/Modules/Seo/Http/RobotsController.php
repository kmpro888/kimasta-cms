<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Http;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;
use CMS\Modules\Site\Contracts\SiteResolverInterface;
use CMS\Modules\Site\Exceptions\InvalidHostException;
use CMS\Modules\Site\Exceptions\SiteNotFoundException;

/**
 * `/robots.txt`, generated per site.
 *
 * A crawler that finds no robots file assumes it may go anywhere, including `/admin/` and `/api/`.
 * This is the cheapest possible thing to get right and the site was serving a 404.
 *
 * The sitemap is declared with an absolute URL because the specification requires one; the base URL
 * falls back to the request's own scheme and host when the site has none configured.
 */
final class RobotsController implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteResolverInterface $sites,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $site = $this->sites->resolve($request->host());
        } catch (SiteNotFoundException|InvalidHostException) {
            throw HttpException::notFound();
        }

        $baseUrl = trim((string) $site->baseUrl());

        if ($baseUrl === '') {
            $scheme = $request->header('X-Forwarded-Proto') === 'https' ? 'https' : 'https';
            $baseUrl = $scheme . '://' . $request->host();
        }

        $lines = [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /api/',
            'Disallow: /comment',
            'Allow: /',
            '',
            '# Generated per site. The sitemap index is rebuilt by cron.',
            'Sitemap: ' . rtrim($baseUrl, '/') . '/sitemap.xml',
            '',
        ];

        return new Response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
