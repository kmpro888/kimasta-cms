<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Middleware;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Middleware\MiddlewareInterface;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Identity\Contracts\AuthenticationServiceInterface;
use CMS\Modules\Site\Contracts\SiteResolverInterface;
use CMS\Modules\Site\Exceptions\InvalidHostException;
use CMS\Modules\Site\Exceptions\SiteNotFoundException;

/**
 * Resolves the tenant and the actor for an admin request.
 *
 * Same rule as the API: both are derived, never accepted from the client. The tenant comes
 * from the host and the actor from the session, so a dashboard cannot be pointed at another
 * tenant by editing a URL.
 */
final class AdminContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SiteResolverInterface $sites,
        private readonly AuthenticationServiceInterface $auth,
        private readonly ActorProviderInterface $actors,
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        try {
            $site = $this->sites->resolve($request->host());
        } catch (SiteNotFoundException|InvalidHostException) {
            throw HttpException::notFound();
        }

        $siteId = $site->id();
        $userId = $this->auth->currentUserId();

        $request = $request
            ->withAttribute('admin.site', $site)
            ->withAttribute('admin.actor', $this->actors->actorForUser($userId, $siteId));

        return $next->handle($request);
    }
}
