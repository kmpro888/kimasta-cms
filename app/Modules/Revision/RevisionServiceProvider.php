<?php

declare(strict_types=1);

namespace CMS\Modules\Revision;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Core\Database\Connection;
use CMS\Core\Database\ConnectionFactory;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Revision\Contracts\AutosaveServiceInterface;
use CMS\Modules\Revision\Contracts\RevisionServiceInterface;
use CMS\Modules\Revision\Repository\PdoRevisionRepository;
use CMS\Modules\Revision\Repository\RevisionRepositoryInterface;
use CMS\Modules\Revision\Service\AutosaveService;
use CMS\Modules\Revision\Service\RevisionService;
use CMS\Shared\Contracts\ClockInterface;

/**
 * Wires the Revision module into the container.
 *
 * Depends on `ContentServiceInterface`, not on the Content repository or table:
 * a revision takes a snapshot through the content contract, so the two modules
 * stay decoupled.
 */
final class RevisionServiceProvider implements ServiceProviderInterface
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
            RevisionRepositoryInterface::class,
            static fn (Container $c): RevisionRepositoryInterface => new PdoRevisionRepository($c->get(Connection::class))
        );

        $container->singleton(RevisionServiceInterface::class, static function (Container $c): RevisionServiceInterface {
            return new RevisionService(
                $c->get(RevisionRepositoryInterface::class),
                $c->get(ContentServiceInterface::class),
                $c->get(ClockInterface::class),
            );
        });

        $container->singleton(AutosaveServiceInterface::class, static function (Container $c): AutosaveServiceInterface {
            return new AutosaveService(
                $c->get(RevisionRepositoryInterface::class),
                $c->get(ClockInterface::class),
            );
        });
    }

    public function boot(Container $container): void
    {
        // No routes: the editor endpoints belong to a later agent.
    }
}
