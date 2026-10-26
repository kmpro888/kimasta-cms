<?php

declare(strict_types=1);

namespace CMS\Core\Bootstrap;

use CMS\Core\Container\Container;

/**
 * A module's entry point into the application container.
 *
 * `register()` must only build bindings (closures); it must not perform
 * I/O. `boot()` runs after all providers are registered and is where
 * routes, listeners and middleware are attached.
 */
interface ServiceProviderInterface
{
    public function register(Container $container): void;

    public function boot(Container $container): void;
}
