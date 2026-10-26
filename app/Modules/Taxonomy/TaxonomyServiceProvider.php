<?php

declare(strict_types=1);

namespace CMS\Modules\Taxonomy;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Core\Database\Connection;
use CMS\Core\Database\ConnectionFactory;
use CMS\Modules\Taxonomy\Contracts\TaxonomyRegistryInterface;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use CMS\Modules\Taxonomy\Contracts\TermRepositoryInterface;
use CMS\Modules\Taxonomy\Repository\PdoTaxonomyRepository;
use CMS\Modules\Taxonomy\Repository\PdoTermRepository;
use CMS\Modules\Taxonomy\Repository\TaxonomyRepositoryInterface;
use CMS\Modules\Taxonomy\Service\TaxonomyRegistry;
use CMS\Modules\Taxonomy\Service\TaxonomyService;
use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;

/**
 * Wires the Taxonomy module into the container.
 */
final class TaxonomyServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        if (!$container->has(Connection::class)) {
            $container->singleton(
                Connection::class,
                static fn (Container $c): Connection => $c->get(ConnectionFactory::class)->create()
            );
        }

        $container->singleton(
            TaxonomyRegistryInterface::class,
            static fn (): TaxonomyRegistryInterface => new TaxonomyRegistry()
        );

        $container->singleton(
            TaxonomyRepositoryInterface::class,
            static fn (Container $c): TaxonomyRepositoryInterface => new PdoTaxonomyRepository($c->get(Connection::class))
        );

        $container->singleton(
            TermRepositoryInterface::class,
            static fn (Container $c): TermRepositoryInterface => new PdoTermRepository($c->get(Connection::class))
        );

        $container->singleton(TaxonomyServiceInterface::class, static function (Container $c): TaxonomyServiceInterface {
            return new TaxonomyService(
                $c->get(TaxonomyRegistryInterface::class),
                $c->get(TaxonomyRepositoryInterface::class),
                $c->get(TermRepositoryInterface::class),
                $c->get(EventDispatcherInterface::class),
                $c->get(ClockInterface::class),
            );
        });
    }

    public function boot(Container $container): void
    {
        // No routes: admin term management belongs to a later agent.
    }
}
