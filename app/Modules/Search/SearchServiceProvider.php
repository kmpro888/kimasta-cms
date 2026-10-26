<?php

declare(strict_types=1);

namespace CMS\Modules\Search;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Core\Database\Connection;
use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Search\Contracts\SearchServiceInterface;
use CMS\Modules\Search\Service\SearchService;

/**
 * Wires content search.
 *
 * The Search module owns no tables: it is a strategy layer over Content's query
 * service, so it never touches another module's data directly. The FULLTEXT index it
 * depends on belongs to Content and is created by a Content migration.
 */
final class SearchServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(
            SearchServiceInterface::class,
            static fn (Container $c): SearchServiceInterface => new SearchService(
                $c->get(Connection::class),
                $c->get(ContentQueryServiceInterface::class),
            )
        );
    }

    public function boot(Container $container): void
    {
        // Nothing to wire: search is called by the API and the public controller.
    }
}
