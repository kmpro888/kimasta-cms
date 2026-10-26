<?php

declare(strict_types=1);

namespace CMS\Modules\Advertising;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Core\Database\Connection;
use CMS\Modules\Advertising\Contracts\AdvertisingServiceInterface;
use CMS\Modules\Advertising\Repository\AdRepositoryInterface;
use CMS\Modules\Advertising\Repository\PdoAdRepository;
use CMS\Modules\Advertising\Service\AdvertisingService;
use CMS\Shared\Contracts\ClockInterface;

/**
 * Wires advertising.
 *
 * Owns `ads` and `ad_rules`. The theme receives rendered placements in its ViewModel, so
 * no template ever queries for an ad.
 */
final class AdvertisingServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(
            AdRepositoryInterface::class,
            static fn (Container $c): AdRepositoryInterface => new PdoAdRepository($c->get(Connection::class))
        );

        $container->singleton(
            AdvertisingServiceInterface::class,
            static fn (Container $c): AdvertisingServiceInterface => new AdvertisingService(
                $c->get(AdRepositoryInterface::class),
                $c->get(ClockInterface::class),
            )
        );
    }

    public function boot(Container $container): void
    {
        // No routes: the API controller is registered by the Api provider.
    }
}
