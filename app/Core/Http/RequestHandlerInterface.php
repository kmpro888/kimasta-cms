<?php

declare(strict_types=1);

namespace CMS\Core\Http;

/**
 * A node in the request handling chain.
 *
 * FROZEN-ADJACENT CONTRACT: the signature is referenced by the middleware
 * specification in the bootstrap document.
 */
interface RequestHandlerInterface
{
    public function handle(Request $request): Response;
}