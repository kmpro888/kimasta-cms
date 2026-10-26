<?php

declare(strict_types=1);

namespace CMS\Core\Routing;

use CMS\Core\Container\Container;
use CMS\Core\Http\HttpException;
use CMS\Core\Http\Middleware\MiddlewareInterface;
use CMS\Core\Http\Middleware\MiddlewarePipeline;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;

/**
 * Route collection and dispatcher.
 */
final class Router implements RequestHandlerInterface
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    /** @var list<MiddlewareInterface> */
    private array $globalMiddleware = [];

    public function __construct(private readonly Container $container)
    {
    }

    public function add(string $method, string $path, mixed $handler, ?string $name = null): Route
    {
        $route = new Route(strtoupper($method), $path, $handler, $name);

        $this->routes[] = $route;

        if ($name !== null) {
            $this->named[$name] = $route;
        }

        return $route;
    }

    public function get(string $path, mixed $handler, ?string $name = null): Route
    {
        return $this->add('GET', $path, $handler, $name);
    }

    public function post(string $path, mixed $handler, ?string $name = null): Route
    {
        return $this->add('POST', $path, $handler, $name);
    }

    public function put(string $path, mixed $handler, ?string $name = null): Route
    {
        return $this->add('PUT', $path, $handler, $name);
    }

    public function patch(string $path, mixed $handler, ?string $name = null): Route
    {
        return $this->add('PATCH', $path, $handler, $name);
    }

    public function delete(string $path, mixed $handler, ?string $name = null): Route
    {
        return $this->add('DELETE', $path, $handler, $name);
    }

    public function head(string $path, mixed $handler, ?string $name = null): Route
    {
        return $this->add('HEAD', $path, $handler, $name);
    }

    public function globalMiddleware(MiddlewareInterface ...$middleware): void
    {
        foreach ($middleware as $item) {
            $this->globalMiddleware[] = $item;
        }
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    public function named(string $name): ?Route
    {
        return $this->named[$name] ?? null;
    }

    public function match(Request $request): RouteMatch
    {
        $path = $request->path();
        $method = $request->method();
        $methodMismatch = false;

        foreach ($this->routes as $route) {
            $params = $route->match($path);

            if ($params === null) {
                continue;
            }

            if ($route->method() === $method) {
                return new RouteMatch($route, $params, $route->middlewareList());
            }

            if ($method === 'HEAD' && $route->method() === 'GET') {
                return new RouteMatch($route, $params, $route->middlewareList());
            }

            $methodMismatch = true;
        }

        if ($methodMismatch) {
            throw HttpException::methodNotAllowed();
        }

        throw HttpException::notFound();
    }

    public function handle(Request $request): Response
    {
        $router = $this;

        // Routing itself is the pipeline destination so that "not found" and
        // "method not allowed" pass through the exception middleware and are
        // rendered with the frozen error envelope instead of escaping.
        $destination = new class ($this->container, $router) implements RequestHandlerInterface {
            public function __construct(
                private readonly Container $container,
                private readonly Router $router,
            ) {
            }

            public function handle(Request $request): Response
            {
                $match = $this->router->match($request);

                $request = $request->withAttribute('route.name', $match->route->name());

                foreach ($match->parameters as $name => $value) {
                    $request = $request->withAttribute('route.' . $name, $value);
                }

                return $this->router->run($request, $match);
            }
        };

        $middleware = $this->globalMiddleware;
        $pipeline = MiddlewarePipeline::withMiddleware($middleware, $destination);
        $response = $pipeline->handle($request);

        if ($request->isMethod('HEAD')) {
            return new Response('', $response->status(), $response->headers());
        }

        return $response;
    }

    /**
     * Execute the matched route's handler behind its route-level middleware.
     *
     * Global middleware is intentionally NOT applied here: it already wrapped
     * the routing step in handle(), so reusing it would run it twice.
     */
    public function run(Request $request, RouteMatch $match): Response
    {
        // Captured parameters are exposed to the handler as `route.<name>`
        // attributes. Without this a controller has no way to read `{id}`: the
        // RouteMatch is not reachable from inside `handle()`.
        foreach ($match->parameters as $__name => $__value) {
            $request = $request->withAttribute('route.' . $__name, $__value);
        }

        $destination = new class ($this->container, $match) implements RequestHandlerInterface {
            public function __construct(
                private readonly Container $container,
                private readonly RouteMatch $match,
            ) {
            }

            public function handle(Request $request): Response
            {
                $handler = $this->match->handler();

                if ($handler instanceof RequestHandlerInterface) {
                    return $handler->handle($request);
                }

                if (is_string($handler) && $this->container->has($handler)) {
                    $resolved = $this->container->get($handler);

                    if ($resolved instanceof RequestHandlerInterface) {
                        return $resolved->handle($request);
                    }

                    if (is_callable($resolved)) {
                        return $this->invoke($resolved, $request);
                    }
                }

                if (is_callable($handler)) {
                    return $this->invoke($handler, $request);
                }

                throw HttpException::notFound('Route handler is not callable.');
            }

            private function invoke(callable $handler, Request $request): Response
            {
                $result = $handler($request);

                if (!$result instanceof Response) {
                    throw HttpException::internalError('Route handler must return a Response.');
                }

                return $result;
            }
        };

        $middleware = [];

        foreach ($match->middleware as $name) {
            if ($this->container->has($name)) {
                $resolved = $this->container->get($name);

                if ($resolved instanceof MiddlewareInterface) {
                    $middleware[] = $resolved;
                }
            }
        }

        $pipeline = MiddlewarePipeline::withMiddleware($middleware, $destination);
        $response = $pipeline->handle($request);

        if ($request->isMethod('HEAD')) {
            return new Response('', $response->status(), $response->headers());
        }

        return $response;
    }
}
