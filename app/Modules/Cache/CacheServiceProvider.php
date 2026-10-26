<?php

declare(strict_types=1);

namespace CMS\Modules\Cache;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Modules\Cache\Contracts\CacheInvalidationServiceInterface;
use CMS\Modules\Cache\Contracts\PageCacheInterface;
use CMS\Modules\Cache\Page\PageCache;
use CMS\Modules\Cache\Service\CacheInvalidationService;
use CMS\Shared\Contracts\CacheInterface;
use CMS\Shared\Contracts\ConfigInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;

/**
 * Wires the Cache module into the container.
 *
 * `boot()` is where the invalidation listeners are attached: registration must not
 * perform I/O, and listening requires the dispatcher to exist.
 */
final class CacheServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(CacheInterface::class, static function (Container $c): CacheInterface {
            /** @var ConfigInterface $config */
            $config = $c->get(ConfigInterface::class);
            $root = self::cacheRoot($config);

            return new FileCache(
                $root . DIRECTORY_SEPARATOR . 'objects',
                (int) $config->get('cache.default_ttl', 3600),
            );
        });

        $container->singleton(PageCacheInterface::class, static function (Container $c): PageCacheInterface {
            /** @var ConfigInterface $config */
            $config = $c->get(ConfigInterface::class);

            return new PageCache(
                self::cacheRoot($config) . DIRECTORY_SEPARATOR . 'pages',
                (int) $config->get('cache.page_ttl', 0),
            );
        });

        $container->singleton(
            CacheInvalidationServiceInterface::class,
            static fn (Container $c): CacheInvalidationServiceInterface => new CacheInvalidationService(
                $c->get(PageCacheInterface::class),
                $c->get(EventDispatcherInterface::class),
            )
        );
    }

    public function boot(Container $container): void
    {
        /** @var CacheInvalidationServiceInterface $invalidation */
        $invalidation = $container->get(CacheInvalidationServiceInterface::class);

        // Event-driven, targeted invalidation. No manual cache clearing is needed
        // anywhere else in the codebase.
        $invalidation->subscribe();
    }

    /**
     * Absolute path to the cache root.
     */
    public static function cacheRoot(ConfigInterface $config): string
    {
        $configured = (string) $config->get('cache.path', '');

        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }

        $storage = (string) $config->get('app.storage_path', '');

        if ($storage === '') {
            $base = (string) $config->get('app.base_path', '');

            if ($base === '') {
                $base = dirname(__DIR__, 3);
            }

            $storage = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'storage';
        }

        return rtrim($storage, '/\\') . DIRECTORY_SEPARATOR . 'cache';
    }
}
