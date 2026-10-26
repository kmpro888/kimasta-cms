<?php

declare(strict_types=1);

namespace CMS\Core\Http\Controller;

use CMS\Core\Http\JsonResponse;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;

/**
 * Liveness probe.
 *
 * Intentionally static: it must never touch the database, so a cached or
 * load-balancer health check cannot wake MySQL. No version, path, or
 * environment detail is disclosed.
 */
final class HealthController implements RequestHandlerInterface
{
    public function handle(Request $request): Response
    {
        return new JsonResponse(['status' => 'ok']);
    }
}