<?php

declare(strict_types=1);

namespace CMS\Core\Http\Middleware;

use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;

/**
 * Executes middleware in registration order.
 *
 * The first registered middleware is the outermost layer, so global
 * concerns such as request ID and exception handling wrap everything else.
 */
final class MiddlewarePipeline implements RequestHandlerInterface
{
    /** @param list<MiddlewareInterface> $middleware */
    public function __construct(
        private readonly array $middleware,
        private readonly RequestHandlerInterface $destination,
    ) {
    }

    public function handle(Request $request): Response
    {
        return $this->dispatch($request, 0);
    }

    /** @param list<MiddlewareInterface> $middleware */
    public static function withMiddleware(
        array $middleware,
        RequestHandlerInterface $destination,
    ): self {
        return new self(array_values($middleware), $destination);
    }

    private function dispatch(Request $request, int $index): Response
    {
        if (!isset($this->middleware[$index])) {
            return $this->destination->handle($request);
        }

        $next = new class ($this, $index + 1) implements RequestHandlerInterface {
            public function __construct(
                private readonly MiddlewarePipeline $pipeline,
                private readonly int $index,
            ) {
            }

            public function handle(Request $request): Response
            {
                return $this->pipeline->dispatchFrom($request, $this->index);
            }
        };

        return $this->middleware[$index]->process($request, $next);
    }

    public function dispatchFrom(Request $request, int $index): Response
    {
        return $this->dispatch($request, $index);
    }
}
