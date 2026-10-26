<?php

declare(strict_types=1);

namespace CMS\Core\Bootstrap;

use CMS\Core\Config\Config;
use CMS\Core\Config\ConfigLoader;
use CMS\Core\Config\Env;
use CMS\Core\Container\Container;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Core\Routing\Router;
use CMS\Core\Support\DebugGuard;
use CMS\Shared\Contracts\ConfigInterface;

/**
 * Application kernel.
 *
 * boot() is deliberately cheap: it loads configuration and registers service
 * providers. No database connection, no session, no plugin scan. handle()
 * returns a Response without emitting it; send is a separate step so tests
 * can assert on responses.
 */
final class Application
{
    private bool $booted = false;

    /** @var list<ServiceProviderInterface> */
    private array $providers = [];

    public function __construct(
        private readonly Container $container,
        private readonly string $basePath,
    ) {
    }

    public static function create(string $basePath, ?string $envFile = null): self
    {
        $basePath = rtrim($basePath, '/\\');

        $env = new Env();
        $env->load($envFile ?? $basePath . DIRECTORY_SEPARATOR . '.env');

        $config = (new ConfigLoader($basePath . DIRECTORY_SEPARATOR . 'config', $env))->load();

        // This is the first point where both values are resolved. Failing here
        // is deliberate: a production host started in debug mode is not
        // something to discover from a leaked stack trace.
        DebugGuard::assertSafe(
            (string) $config->get('app.env', 'production'),
            (bool) $config->get('app.debug', false),
        );

        $container = new Container();
        $container->instance(Env::class, $env);
        $container->instance(Config::class, $config);
        $container->instance(ConfigInterface::class, $config);

        $application = new self($container, $basePath);
        $container->instance(self::class, $application);

        return $application;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    public function provider(ServiceProviderInterface $provider): self
    {
        $this->providers[] = $provider;

        return $this;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        /** @var ConfigInterface $config */
        $config = $this->container->get(ConfigInterface::class);

        $timezone = (string) $config->get('app.timezone', 'UTC');

        if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            date_default_timezone_set($timezone);
        }

        $providers = $this->providers;

        // Core is always first and always present. Previously this block only
        // added CoreServiceProvider when no other provider was registered, which
        // meant that registering a single module provider silently dropped every
        // Core service (router, logger, database factory).
        array_unshift($providers, new CoreServiceProvider());

        foreach ($providers as $provider) {
            $provider->register($this->container);
        }

        foreach ($providers as $provider) {
            $provider->boot($this->container);
        }

        $this->booted = true;
    }

    public function handle(Request $request): Response
    {
        if (!$this->booted) {
            $this->boot();
        }

        /** @var Router $router */
        $router = $this->container->get(Router::class);

        return $router->handle($request);
    }

    public function terminate(Response $response): void
    {
        $response->send();
    }
}
