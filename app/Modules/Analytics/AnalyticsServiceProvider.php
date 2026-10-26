<?php

declare(strict_types=1);

namespace CMS\Modules\Analytics;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Modules\Analytics\Contracts\AnalyticsServiceInterface;
use CMS\Modules\Analytics\Service\AnalyticsService;
use CMS\Modules\Site\Contracts\SettingsServiceInterface;

/**
 * Wires analytics snippet rendering.
 *
 * Owns no tables: configuration lives in site settings under the `analytics`
 * namespace.
 */
final class AnalyticsServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(
            AnalyticsServiceInterface::class,
            static fn (Container $c): AnalyticsServiceInterface => new AnalyticsService(
                $c->get(SettingsServiceInterface::class)
            )
        );
    }

    public function boot(Container $container): void
    {
        // The theme asks for the snippet through the contract; nothing to register.
    }
}
