<?php

declare(strict_types=1);

namespace CMS\Modules\Seo;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Core\Database\Connection;
use CMS\Core\Database\ConnectionFactory;
use CMS\Core\Routing\Router;
use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Contracts\ContentTypeRegistryInterface;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Seo\Contracts\BreadcrumbServiceInterface;
use CMS\Modules\Seo\Http\SitemapController;
use CMS\Modules\Seo\Contracts\RedirectServiceInterface;
use CMS\Modules\Seo\Contracts\SeoServiceInterface;
use CMS\Modules\Seo\Contracts\SitemapServiceInterface;
use CMS\Modules\Seo\Repository\PdoRedirectRepository;
use CMS\Modules\Seo\Repository\PdoSeoRepository;
use CMS\Modules\Seo\Repository\RedirectRepositoryInterface;
use CMS\Modules\Seo\Repository\SeoRepositoryInterface;
use CMS\Modules\Seo\Service\BreadcrumbService;
use CMS\Modules\Seo\Service\RedirectService;
use CMS\Modules\Seo\Service\SeoService;
use CMS\Modules\Seo\Service\SitemapService;
use CMS\Modules\Site\Contracts\SiteResolverInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;
use CMS\Shared\Contracts\ConfigInterface;

/**
 * Wires the SEO module into the container.
 *
 * Depends on Content, Identity, Media and Site through their published contracts —
 * never on their tables.
 */
final class SeoServiceProvider implements ServiceProviderInterface
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
            SeoRepositoryInterface::class,
            static fn (Container $c): SeoRepositoryInterface => new PdoSeoRepository($c->get(Connection::class))
        );

        $container->singleton(
            RedirectRepositoryInterface::class,
            static fn (Container $c): RedirectRepositoryInterface => new PdoRedirectRepository($c->get(Connection::class))
        );

        $container->singleton(SeoService::class, static function (Container $c): SeoService {
            return new SeoService(
                $c->get(SeoRepositoryInterface::class),
                $c->get(ContentServiceInterface::class),
                $c->get(ContentTypeRegistryInterface::class),
                $c->get(UrlGeneratorInterface::class),
                $c->get(SiteServiceInterface::class),
                $c->get(UserServiceInterface::class),
                $c->get(MediaServiceInterface::class),
            );
        });

        $container->singleton(
            SeoServiceInterface::class,
            static fn (Container $c): SeoServiceInterface => $c->get(SeoService::class)
        );

        $container->singleton(
            SitemapServiceInterface::class,
            static fn (Container $c): SitemapServiceInterface => new SitemapService(
                $c->get(ContentQueryServiceInterface::class),
                $c->get(UrlGeneratorInterface::class),
                $c->get(SiteServiceInterface::class),
                $c->get(SeoService::class),
                self::sitemapDirectory($c->get(ConfigInterface::class)),
            )
        );

        $container->singleton(
            RedirectServiceInterface::class,
            static fn (Container $c): RedirectServiceInterface => new RedirectService(
                $c->get(RedirectRepositoryInterface::class)
            )
        );

        $container->singleton(
            BreadcrumbServiceInterface::class,
            static fn (Container $c): BreadcrumbServiceInterface => new BreadcrumbService(
                $c->get(ContentServiceInterface::class),
                $c->get(UrlGeneratorInterface::class),
                $c->get(SiteServiceInterface::class),
            )
        );

        // The route resolves this class by name, so it needs an explicit binding.
        $container->singleton(
            SitemapController::class,
            static fn (Container $c): SitemapController => new SitemapController(
                $c->get(SiteResolverInterface::class),
                $c->get(SitemapServiceInterface::class),
            )
        );
    }

    public function boot(Container $container): void
    {
        /** @var Router $router */
        $router = $container->get(Router::class);

        // Registered here rather than in the theme layer: the SEO module owns the sitemap,
        // and this provider boots before Theme's catch-all, so the exact path wins over
        // `/{path:.*}`.
        $router->get('/sitemap.xml', SitemapController::class, 'seo.sitemap');
    }

    /**
     * Absolute directory for generated sitemap artifacts.
     */
    public static function sitemapDirectory(ConfigInterface $config): string
    {
        $storage = (string) $config->get('app.storage_path', '');

        if ($storage === '') {
            $base = (string) $config->get('app.base_path', '');

            if ($base === '') {
                $base = dirname(__DIR__, 3);
            }

            $storage = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'storage';
        }

        return rtrim($storage, '/\\') . DIRECTORY_SEPARATOR . 'cache'
            . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'sitemaps';
    }
}
