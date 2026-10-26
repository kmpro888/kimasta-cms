<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Middleware;

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
 * Resolves the tenant and the actor for every API request.
 *
 * Both are derived, never accepted from the client:
 *
 *   - the tenant comes from the request host, so `?site_id=` is meaningless;
 *   - the actor comes from the session, so `?user_id=` is meaningless.
 *
 * That is what makes "normal endpoints do not accept arbitrary site_id" true by
 * construction rather than by review.
 */
final class ApiContextMiddleware implements MiddlewareInterface
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
        } catch (SiteNotFoundException) {
            throw HttpException::notFound();
        } catch (InvalidHostException) {
            throw HttpException::notFound();
        }

        $siteId = $site->id();
        $userId = $this->auth->currentUserId();

        $request = $request
            ->withAttribute('api.site', $site)
            ->withAttribute('api.actor', $this->actors->actorForUser($userId, $siteId));

        return $next->handle($request);
    }
}
