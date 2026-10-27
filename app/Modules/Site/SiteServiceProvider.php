<?php

declare(strict_types=1);

namespace CMS\Modules\Site;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Core\Database\Connection;
use CMS\Core\Database\ConnectionFactory;
use CMS\Modules\Site\Contracts\SettingsServiceInterface;
use CMS\Modules\Site\Contracts\SiteResolverInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;
use CMS\Modules\Site\Repository\DomainRepositoryInterface;
use CMS\Modules\Site\Repository\PdoDomainRepository;
use CMS\Modules\Site\Repository\PdoSettingsRepository;
use CMS\Modules\Site\Repository\PdoSiteRepository;
use CMS\Modules\Site\Repository\SettingsRepositoryInterface;
use CMS\Modules\Site\Repository\SiteRepositoryInterface;
use CMS\Modules\Site\Service\SettingsService;
use CMS\Modules\Site\Service\SiteResolver;
use CMS\Modules\Site\Service\SiteService;
use CMS\Modules\Site\Support\DomainMap;
use CMS\Modules\Site\Support\HostNormalizer;
use CMS\Shared\Contracts\ConfigInterface;

/**
 * Wires the Site module into the container.
 *
 * Registration only builds closures, so booting the application still performs
 * no I/O beyond reading config. The PDO connection, the compiled domain map and
 * the settings cache are all resolved lazily, which is what keeps a cached
 * anonymous request cheap.
 */
final class SiteServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        // Core owns the factory; the module only asks for a connection when a
        // repository is actually resolved.
        if (!$container->has(Connection::class)) {
            $container->singleton(
                Connection::class,
                static fn (Container $c): Connection => $c->get(ConnectionFactory::class)->create()
            );
        }

        $container->singleton(HostNormalizer::class, static fn (): HostNormalizer => new HostNormalizer());

        $container->singleton(DomainMap::class, static function (Container $c): DomainMap {
            // The compiled map is scoped to its database.
            //
            // It used to live at `system/domain-map.php` for every installation on the machine,
            // and the test harness regenerates it from the test database. Running the suite
            // therefore overwrote the map the development site reads. The visible effect was
            // small -- one extra site lookup per request until it was rebuilt -- but the
            // dangerous one is a live domain mapped to an id that only exists in the test
            // database: that is a 404 on the whole site until somebody regenerates.
            //
            // Naming the directory after the database makes the two artifacts distinct, so
            // neither can silently answer for the other.
            $config = $c->get(ConfigInterface::class);
            $database = (string) $config->get('database.database', '');
            $scope = $database === ''
                ? 'default'
                : (string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $database);

            return new DomainMap(
                self::systemCacheDirectory($config) . DIRECTORY_SEPARATOR . $scope
            );
        });

        $container->singleton(
            SiteRepositoryInterface::class,
            static fn (Container $c): SiteRepositoryInterface => new PdoSiteRepository($c->get(Connection::class))
        );

        $container->singleton(
            DomainRepositoryInterface::class,
            static fn (Container $c): DomainRepositoryInterface => new PdoDomainRepository($c->get(Connection::class))
        );

        $container->singleton(
            SettingsRepositoryInterface::class,
            static fn (Container $c): SettingsRepositoryInterface => new PdoSettingsRepository($c->get(Connection::class))
        );

        $container->singleton(SiteResolverInterface::class, static function (Container $c): SiteResolverInterface {
            return new SiteResolver(
                $c->get(HostNormalizer::class),
                $c->get(DomainMap::class),
                $c->get(DomainRepositoryInterface::class),
                $c->get(SiteRepositoryInterface::class),
            );
        });

        $container->singleton(SiteServiceInterface::class, static function (Container $c): SiteServiceInterface {
            return new SiteService(
                $c->get(SiteRepositoryInterface::class),
                $c->get(DomainRepositoryInterface::class),
                $c->get(DomainMap::class),
                $c->get(HostNormalizer::class),
            );
        });

        $container->singleton(SettingsServiceInterface::class, static function (Container $c): SettingsServiceInterface {
            return new SettingsService(
                $c->get(SettingsRepositoryInterface::class),
                $c->get(SiteRepositoryInterface::class),
                self::systemCacheDirectory($c->get(ConfigInterface::class)),
            );
        });

    }

    public function boot(Container $container): void
    {
        // No routes or listeners yet: resolution is invoked by the request
        // pipeline that Agent 12/13 will wire, not by this module.
    }

    /**
     * Absolute path to the compiled system cache directory.
     */
    public static function systemCacheDirectory(ConfigInterface $config): string
    {
        return self::storageRoot($config) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'system';
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
