<?php

declare(strict_types=1);

namespace CMS\Modules\Plugin\Service;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;
use CMS\Modules\Plugin\Contracts\PluginManagerInterface;
use CMS\Modules\Site\Contracts\SiteResolverInterface;
use CMS\Modules\Site\Exceptions\InvalidHostException;
use CMS\Modules\Site\Exceptions\SiteNotFoundException;
use Throwable;

/**
 * Runs a plugin's route only when the plugin is active for the resolved tenant.
 *
 * Plugin routes are registered globally at boot, because routing happens before a
 * tenant is known. Activation is per site, so the decision has to be deferred to
 * request time — otherwise activating a plugin on Site A would expose its endpoints on
 * every other tenant.
 */
final class PluginRouteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly mixed $handler,
        private readonly string $plugin,
        private readonly PluginManagerInterface $manager,
        private readonly SiteResolverInterface $sites,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $siteId = $this->sites->siteIdFor($request->host());
        } catch (SiteNotFoundException|InvalidHostException) {
            throw HttpException::notFound();
        }

        if (!$this->manager->isActiveFor($siteId, $this->plugin)) {
            // A plugin endpoint on a tenant where the plugin is off must look absent,
            // not forbidden: 403 would confirm that the endpoint exists.
            throw HttpException::notFound();
        }

        return $this->invoke($request);
    }

    private function invoke(Request $request): Response
    {
        try {
            $result = ($this->handler)($request);
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            // A plugin failure must not leak its internals through the host.
            throw HttpException::internalError('The plugin could not handle this request.');
        }

        if (!$result instanceof Response) {
            throw HttpException::internalError('A plugin route must return a Response.');
        }

        return $result;
    }
}
