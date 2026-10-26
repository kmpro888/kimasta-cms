<?php

declare(strict_types=1);

namespace CMS\Modules\Contribution;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Contribution\Contracts\ContributorServiceInterface;
use CMS\Modules\Contribution\Service\ContributorService;
use CMS\Modules\Editorial\Contracts\EditorialWorkflowInterface;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;

/**
 * Wires the contributor surface.
 *
 * Owns no tables and no routes: it is the rule layer between the authenticated author
 * and the content services, so the API and any future UI share one implementation of
 * "what an author may do".
 */
final class ContributionServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(
            ContributorServiceInterface::class,
            static fn (Container $c): ContributorServiceInterface => new ContributorService(
                $c->get(ContentServiceInterface::class),
                $c->get(ContentQueryServiceInterface::class),
                $c->get(EditorialWorkflowInterface::class),
                $c->get(ActorProviderInterface::class),
            )
        );
    }

    public function boot(Container $container): void
    {
        // Routes are registered by the Api provider: the contributor surface is HTTP.
    }
}
