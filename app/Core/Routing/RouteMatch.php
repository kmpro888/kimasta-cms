<?php

declare(strict_types=1);

namespace CMS\Core\Routing;

use CMS\Core\Http\RequestHandlerInterface;

/**
 * A matched route plus its captured parameters.
 */
final class RouteMatch
{
    /**
     * @param array<string, string> $parameters
     * @param list<string> $middleware
     */
    public function __construct(
        public readonly Route $route,
        public readonly array $parameters = [],
        public readonly array $middleware = [],
    ) {
    }

    /**
     * The raw handler: a RequestHandlerInterface, a callable, or a
     * class-string resolved later by the container.
     */
    public function handler(): mixed
    {
        return $this->route->handler();
    }
}
