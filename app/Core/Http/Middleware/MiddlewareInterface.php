<?php

declare(strict_types=1);

namespace CMS\Core\Http\Middleware;

use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;

/**
 * Middleware contract.
 *
 * FROZEN CONTRACT (bootstrap specification §23).
 */
interface MiddlewareInterface
{
    public function process(
        Request $request,
        RequestHandlerInterface $next
    ): Response;
}