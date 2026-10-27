<?php

declare(strict_types=1);

namespace CMS\Modules\Theme;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Core\Security\RateLimiter;
use CMS\Core\Filesystem\LockInterface;
use CMS\Core\Routing\Router;
use CMS\Modules\Advertising\Contracts\AdvertisingServiceInterface;
use CMS\Modules\Comment\Contracts\CommentServiceInterface;
use CMS\Modules\Analytics\Contracts\AnalyticsServiceInterface;
use CMS\Modules\Cache\Contracts\PageCacheInterface;
use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Contracts\ContentTypeRegistryInterface;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Seo\Contracts\BreadcrumbServiceInterface;
use CMS\Modules\Seo\Contracts\RedirectServiceInterface;
use CMS\Modules\Seo\Contracts\SeoServiceInterface;
use CMS\Modules\Site\Contracts\SiteResolverInterface;
use CMS\Modules\Site\Repository\SiteRepositoryInterface;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use CMS\Modules\Theme\Contracts\TemplateResolverInterface;
use CMS\Modules\Theme\Contracts\ThemeRegistryInterface;
use CMS\Modules\Theme\Contracts\ViewRendererInterface;
use CMS\Modules\Theme\Http\PublicPageController;
use CMS\Modules\Theme\Http\ThemeAssetController;
use CMS\Modules\Theme\Http\PublicCommentController;
use CMS\Modules\Theme\Rendering\PageViewModelFactory;
use CMS\Modules\Theme\Service\TemplateResolver;
use CMS\Modules\Theme\Service\ThemeRegistry;
use CMS\Modules\Theme\Service\ViewRenderer;
use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\ConfigInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;

/**
 * Wires the Theme module into the container.
 */
final class ThemeServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(ThemeRegistryInterface::class, static function (Container $c): ThemeRegistryInterface {
            /** @var ConfigInterface $config */
            $config = $c->get(ConfigInterface::class);

            return new ThemeRegistry(
                self::themesRoot($config),
                $c->get(SiteRepositoryInterface::class),
                $c->get(EventDispatcherInterface::class),
                $c->get(ClockInterface::class),
            );
        });

        $container->singleton(
            TemplateResolverInterface::class,
            static fn (Container $c): TemplateResolverInterface => new TemplateResolver(
                $c->get(ThemeRegistryInterface::class)
            )
        );

        $container->singleton(
            ViewRendererInterface::class,
            static fn (Container $c): ViewRendererInterface => new ViewRenderer(
                $c->get(ThemeRegistryInterface::class)
            )
        );

        $container->singleton(PageViewModelFactory::class, static function (Container $c): PageViewModelFactory {
            return new PageViewModelFactory(
                $c->get(ContentQueryServiceInterface::class),
                $c->get(UrlGeneratorInterface::class),
                $c->get(SeoServiceInterface::class),
                $c->get(BreadcrumbServiceInterface::class),
                $c->get(TaxonomyServiceInterface::class),
                $c->get(MediaServiceInterface::class),
                $c->has(AdvertisingServiceInterface::class) ? $c->get(AdvertisingServiceInterface::class) : null,
                $c->has(AnalyticsServiceInterface::class) ? $c->get(AnalyticsServiceInterface::class) : null,
                $c->has(CommentServiceInterface::class) ? $c->get(CommentServiceInterface::class) : null,
            );
        });

        $container->singleton(PublicPageController::class, static function (Container $c): PublicPageController {
            return new PublicPageController(
                $c->get(SiteResolverInterface::class),
                $c->get(PageCacheInterface::class),
                $c->get(ContentQueryServiceInterface::class),
                $c->get(RedirectServiceInterface::class),
                $c->get(ThemeRegistryInterface::class),
                $c->get(TemplateResolverInterface::class),
                $c->get(ViewRendererInterface::class),
                $c->get(PageViewModelFactory::class),
                $c->get(TaxonomyServiceInterface::class),
                $c->get(ContentTypeRegistryInterface::class),
                $c->has(LockInterface::class) ? $c->get(LockInterface::class) : null,
            );
        });

        // The router resolves the handler by class name, so a route without a binding falls
        // through to a 404 even though the pattern matched.
        $container->singleton(
            ThemeAssetController::class,
            static fn (Container $c): ThemeAssetController => new ThemeAssetController(
                $c->get(ThemeRegistryInterface::class),
            ),
        );

        $container->singleton(
            PublicCommentController::class,
            static fn (Container $c): PublicCommentController => new PublicCommentController(
                $c->get(SiteResolverInterface::class),
                $c->get(CommentServiceInterface::class),
                $c->get(ContentServiceInterface::class),
                $c->get(UrlGeneratorInterface::class),
                $c->get(RateLimiter::class),
            ),
        );
    }

    public function boot(Container $container): void
    {
        /** @var Router $router */
        $router = $container->get(Router::class);

        // Theme assets live outside the web root, so they need a route. Registered before the
    // catch-all: a stylesheet must not be answered by the page renderer.
    $router->get(
        '/themes/{theme:[A-Za-z0-9_-]+}/assets/{file:[A-Za-z0-9._-]+}',
        ThemeAssetController::class,
        'theme.asset',
    );

    // Public comment posting. A POST route of its own rather than a verb on the catch-all: the
    // authorisation, the honeypot and the rate limit belong to one action, and the catch-all stays
    // a pure GET renderer. Registered before it, so the path cannot be swallowed.
    $router->post('/comment', PublicCommentController::class, 'public.comment');

    // One catch-all route renders every public page. The negative lookahead
        // keeps /api/ and /admin/ out of the theme entirely, so an unknown API
        // path still yields the frozen JSON error envelope from the router rather
        // than an HTML 404 from the theme.
        $router->get('/{path:(?!api/|admin/|admin$).*}', PublicPageController::class, 'public.page');
    }

    /**
     * Absolute path to the themes root.
     */
    public static function themesRoot(ConfigInterface $config): string
    {
        $configured = (string) $config->get('theme.path', '');

        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }

        $base = (string) $config->get('app.base_path', '');

        if ($base === '') {
            $base = dirname(__DIR__, 3);
        }

        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'themes';
    }
}
