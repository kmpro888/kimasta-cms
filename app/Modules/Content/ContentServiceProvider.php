<?php

declare(strict_types=1);

namespace CMS\Modules\Content;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Core\Database\Connection;
use CMS\Core\Database\ConnectionFactory;
use CMS\Modules\Content\Contracts\BlockCompilerInterface;
use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Contracts\ContentRepositoryInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Contracts\ContentTypeRegistryInterface;
use CMS\Modules\Content\Contracts\SlugServiceInterface;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;
use CMS\Modules\Content\Repository\PdoContentRepository;
use CMS\Modules\Content\Repository\PdoUrlRegistryRepository;
use CMS\Modules\Content\Repository\UrlRegistryRepositoryInterface;
use CMS\Modules\Content\Service\ContentQueryService;
use CMS\Modules\Content\Service\ContentService;
use CMS\Modules\Content\Service\ContentTypeRegistry;
use CMS\Modules\Content\Service\PlainBlockCompiler;
use CMS\Modules\Content\Service\SlugService;
use CMS\Modules\Content\Service\UrlGenerator;
use CMS\Modules\Content\Support\ContentMediaUsageChecker;
use CMS\Modules\Content\Support\ContentScheduledFinder;
use CMS\Modules\Editorial\Contracts\ScheduledContentFinderInterface;
use CMS\Modules\Media\Contracts\MediaUsageCheckerInterface;
use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;

/**
 * Wires the Content module into the container.
 *
 * `BlockCompilerInterface` is bound to the plain fallback here. Agent 11 owns the
 * real block engine and will rebind this one line; nothing else in Content needs
 * to change because the dependency is an interface.
 */
final class ContentServiceProvider implements ServiceProviderInterface
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
            ContentRepositoryInterface::class,
            static fn (Container $c): ContentRepositoryInterface => new PdoContentRepository($c->get(Connection::class))
        );

        $container->singleton(
            UrlRegistryRepositoryInterface::class,
            static fn (Container $c): UrlRegistryRepositoryInterface => new PdoUrlRegistryRepository($c->get(Connection::class))
        );

        $container->singleton(
            BlockCompilerInterface::class,
            static fn (): BlockCompilerInterface => new PlainBlockCompiler()
        );

        $container->singleton(
            ContentTypeRegistryInterface::class,
            static fn (): ContentTypeRegistryInterface => new ContentTypeRegistry()
        );

        $container->singleton(
            SlugServiceInterface::class,
            static fn (Container $c): SlugServiceInterface => new SlugService(
                $c->get(ContentRepositoryInterface::class)
            )
        );

        $container->singleton(
            UrlGeneratorInterface::class,
            static fn (Container $c): UrlGeneratorInterface => new UrlGenerator(
                $c->get(UrlRegistryRepositoryInterface::class)
            )
        );

        $container->singleton(ContentServiceInterface::class, static function (Container $c): ContentServiceInterface {
            return new ContentService(
                $c->get(ContentRepositoryInterface::class),
                $c->get(SlugServiceInterface::class),
                $c->get(UrlGeneratorInterface::class),
                $c->get(BlockCompilerInterface::class),
                $c->get(ContentTypeRegistryInterface::class),
                $c->get(EventDispatcherInterface::class),
                $c->get(ClockInterface::class),
            );
        });

        $container->singleton(
            ContentQueryServiceInterface::class,
            static fn (Container $c): ContentQueryServiceInterface => new ContentQueryService(
                $c->get(ContentRepositoryInterface::class),
                $c->get(UrlRegistryRepositoryInterface::class),
            )
        );

        // Content owns `contents`, so it answers the Media module's
        // "is this file still referenced?" question instead of letting Media
        // query a foreign table.
        if (!$container->has(MediaUsageCheckerInterface::class)) {
            $container->singleton(
                MediaUsageCheckerInterface::class,
                static fn (Container $c): MediaUsageCheckerInterface => new ContentMediaUsageChecker(
                    $c->get(Connection::class)
                )
            );
        }

        // Same seam for the Editorial scheduler: Content owns `scheduled_at`, so
        // Content performs the query and Editorial consumes the answer.
        if (!$container->has(ScheduledContentFinderInterface::class)) {
            $container->singleton(
                ScheduledContentFinderInterface::class,
                static fn (Container $c): ScheduledContentFinderInterface => new ContentScheduledFinder(
                    $c->get(Connection::class)
                )
            );
        }
    }

    public function boot(Container $container): void
    {
        // No routes yet: public rendering and the admin API belong to later
        // agents. This module exposes services only.
    }
}
