<?php

declare(strict_types=1);

namespace CMS\Core\Bootstrap;

use CMS\Core\Agent\AgentRegistry;
use CMS\Core\Config\Config;
use CMS\Core\Container\Container;
use CMS\Core\Database\ConnectionFactory;
use CMS\Core\Events\EventDispatcher;
use CMS\Core\Events\ListenerRegistry;
use CMS\Core\Filesystem\FileLock;
use CMS\Core\Filesystem\Filesystem;
use CMS\Core\Filesystem\FilesystemInterface;
use CMS\Core\Filesystem\LockInterface;
use CMS\Core\Http\Controller\AgentDashboardController;
use CMS\Core\Http\Controller\HealthController;
use CMS\Core\Http\Middleware\ExceptionMiddleware;
use CMS\Core\Http\Middleware\RequestIdMiddleware;
use CMS\Core\Logging\Logger;
use CMS\Core\Logging\LoggerInterface;
use CMS\Core\Routing\Router;
use CMS\Core\Security\CsrfTokenManager;
use CMS\Core\Support\SystemClock;
use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\ConfigInterface;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;
use CMS\Shared\Contracts\SessionInterface;
use CMS\Shared\Contracts\TransactionManagerInterface;
use CMS\Core\Database\TransactionManager;

/**
 * Registers the Core Platform services.
 *
 * Everything expensive (PDO connection, cache, plugin loading) is lazy.
 * Registration only builds closures, so booting the application for a
 * cached public request costs no I/O beyond config file reads.
 */
final class CoreServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(ConfigInterface::class, static fn (Container $c): ConfigInterface => $c->get(Config::class));
        $container->singleton(ClockInterface::class, static fn (): ClockInterface => new SystemClock('UTC'));

        // CSRF protection. The frozen contract had no implementation, so every
        // cookie-authenticated mutation was forgeable. The binding is lazy because
        // SessionInterface is registered by the Identity provider, which loads after
        // Core; the closure resolves it on first use, at request time.
        $container->singleton(
            CsrfTokenManagerInterface::class,
            static fn (Container $c): CsrfTokenManagerInterface => new CsrfTokenManager(
                $c->get(SessionInterface::class)
            )
        );

        $container->singleton(FilesystemInterface::class, static function (Container $c): FilesystemInterface {
            /** @var ConfigInterface $config */
            $config = $c->get(ConfigInterface::class);

            return new Filesystem(self::storageRoot($config));
        });

        $container->singleton(LockInterface::class, static function (Container $c): LockInterface {
            /** @var ConfigInterface $config */
            $config = $c->get(ConfigInterface::class);

            return new FileLock(self::storageRoot($config) . DIRECTORY_SEPARATOR . 'locks');
        });

        $container->singleton(LoggerInterface::class, static function (Container $c): LoggerInterface {
            /** @var ConfigInterface $config */
            $config = $c->get(ConfigInterface::class);
            $path = (string) $config->get('logging.path', '');

            if ($path === '') {
                $path = self::storageRoot($config) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
            }

            return new Logger(
                path: $path,
                minLevel: (string) $config->get('logging.level', 'warning'),
                clock: $c->get(ClockInterface::class),
            );
        });

        $container->singleton(ConnectionFactory::class, static function (Container $c): ConnectionFactory {
            /** @var ConfigInterface $config */
            $config = $c->get(ConfigInterface::class);
            /** @var array<string, mixed> $database */
            $database = (array) $config->get('database', []);

            return new ConnectionFactory($database);
        });

        $container->singleton(ListenerRegistry::class, static fn (): ListenerRegistry => new ListenerRegistry());

        $container->singleton(EventDispatcherInterface::class, static function (Container $c): EventDispatcherInterface {
            return new EventDispatcher(
                $c->get(ListenerRegistry::class),
                $c->get(LoggerInterface::class),
            );
        });

        $container->bind(TransactionManagerInterface::class, static function (Container $c): TransactionManagerInterface {
            $factory = $c->get(ConnectionFactory::class);
            $profiler = null;

            return new TransactionManager($factory->create());
        });

        $container->singleton(AgentRegistry::class, static function (Container $c): AgentRegistry {
            /** @var ConfigInterface $config */
            $config = $c->get(ConfigInterface::class);
            $base = (string) $config->get('app.base_path', '');

            if ($base === '') {
                $base = dirname(__DIR__, 3);
            }

            return new AgentRegistry(
                $c->get(FilesystemInterface::class),
                rtrim($base, '/\\'),
            );
        });

        $container->singleton(Router::class, static fn (Container $c): Router => new Router($c));
        $container->bind(HealthController::class, static fn (): HealthController => new HealthController());
        $container->bind(AgentDashboardController::class, static function (Container $c): AgentDashboardController {
            return new AgentDashboardController($c->get(AgentRegistry::class));
        });
    }

    public function boot(Container $container): void
    {
        /** @var ConfigInterface $config */
        $config = $container->get(ConfigInterface::class);
        $debug = (bool) $config->get('app.debug', false);

        /** @var Router $router */
        $router = $container->get(Router::class);

        $router->globalMiddleware(
            new RequestIdMiddleware(),
            new ExceptionMiddleware($debug, $container->get(LoggerInterface::class)),
        );

        $router->get('/api/v1/health', HealthController::class, 'api.health');

        // Operational dashboard. Read-only and filesystem-backed, so it is
        // safe to mount outside the public cache; access control belongs to
        // Agent 03 (Identity/Auth/RBAC) and is not duplicated here.
        $router->get('/admin/agents', AgentDashboardController::class, 'admin.agents');
    }

    private static function storageRoot(ConfigInterface $config): string
    {
        $configured = (string) $config->get('app.storage_path', '');

        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }

        $base = (string) $config->get('app.base_path', '');

        if ($base === '') {
            $base = dirname(__DIR__, 3);
        }

        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'storage';
    }
}
