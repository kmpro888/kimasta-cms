<?php

declare(strict_types=1);

namespace CMS\Modules\Admin;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Core\Routing\Router;
use CMS\Core\Security\RateLimiter;
use CMS\Modules\Admin\Http\Controller\AdController;
use CMS\Modules\Admin\Http\Controller\AuthController;
use CMS\Modules\Admin\Http\Controller\ContentController;
use CMS\Modules\Admin\Http\Controller\CommentController;
use CMS\Modules\Advertising\Contracts\AdvertisingServiceInterface;
use CMS\Modules\Admin\Http\Controller\DashboardController;
use CMS\Modules\Admin\Http\Controller\MediaController;
use CMS\Modules\Admin\Http\Controller\NotificationController;
use CMS\Modules\Admin\Http\Controller\PluginController;
use CMS\Modules\Admin\Http\Controller\PasswordResetAdminController;
use CMS\Modules\Admin\Http\Controller\RedirectController;
use CMS\Modules\Admin\Http\Controller\RevisionController;
use CMS\Modules\Admin\Http\Controller\SettingsController;
use CMS\Modules\Admin\Http\Controller\AuditController;
use CMS\Modules\Admin\Http\Controller\SiteController;
use CMS\Modules\Admin\Http\Controller\UserController;
use CMS\Modules\Admin\Http\Middleware\AdminAuthMiddleware;
use CMS\Modules\Admin\Http\Middleware\AdminContextMiddleware;
use CMS\Modules\Admin\Http\Middleware\AdminErrorMiddleware;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Audit\Contracts\AuditLoggerInterface;
use CMS\Modules\Admin\Support\BlockFormCompiler;
use CMS\Modules\Cache\Contracts\PageCacheInterface;
use CMS\Modules\Comment\Contracts\CommentServiceInterface;
use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Contribution\Contracts\ContributorServiceInterface;
use CMS\Modules\Editorial\Contracts\EditorialWorkflowInterface;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Identity\Contracts\AuthenticationServiceInterface;
use CMS\Modules\Identity\Contracts\RoleServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Identity\Contracts\PasswordResetServiceInterface;
use CMS\Modules\Plugin\Contracts\PluginManagerInterface;
use CMS\Modules\Revision\Contracts\RevisionServiceInterface;
use CMS\Modules\Notification\Contracts\NotificationServiceInterface;
use CMS\Modules\Seo\Contracts\RedirectServiceInterface;
use CMS\Modules\Seo\Contracts\SeoServiceInterface;
use CMS\Modules\Site\Contracts\SiteResolverInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use CMS\Modules\Theme\Contracts\ThemeRegistryInterface;
use CMS\Modules\Theme\Contracts\ViewRendererInterface;
use CMS\Shared\Contracts\ConfigInterface;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;
/**
 * The admin presentation layer.
 *
 * It owns HTTP and views and nothing else: every action goes through the same application
 * service the API calls, so the two surfaces cannot disagree about the rules.
 *
 * Route order matters only in appearance here -- the public catch-all already excludes
 * `admin/`, and this provider boots before Theme's, so `/admin` resolves explicitly.
 */
final class AdminServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        // Resolved lazily: this provider registers before or after Theme depending on the
        // bootstrap order, and `register()` must not resolve another module's binding.
        $container->singleton(AdminView::class, static fn (Container $c): AdminView => new AdminView(
            $c->get(ViewRendererInterface::class),
            __DIR__ . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'views',
        ));

        $container->singleton(BlockFormCompiler::class, static fn (): BlockFormCompiler => new BlockFormCompiler());

        $container->singleton(
            AdminContextMiddleware::class,
            static fn (Container $c): AdminContextMiddleware => new AdminContextMiddleware(
                $c->get(SiteResolverInterface::class),
                $c->get(AuthenticationServiceInterface::class),
                $c->get(ActorProviderInterface::class),
            )
        );

        $container->singleton(
            AdminAuthMiddleware::class,
            static fn (Container $c): AdminAuthMiddleware => new AdminAuthMiddleware(
                $c->get(CsrfTokenManagerInterface::class),
            )
        );

        $container->singleton(
            AdminErrorMiddleware::class,
            static fn (Container $c): AdminErrorMiddleware => new AdminErrorMiddleware(
                $c->get(AdminView::class),
                (bool) $c->get(ConfigInterface::class)->get('app.debug', false),
            )
        );

        $container->singleton(
            AuthController::class,
            static fn (Container $c): AuthController => new AuthController(
                $c->get(AdminView::class),
                $c->get(SessionInterface::class),
                $c->get(CsrfTokenManagerInterface::class),
                $c->get(ActorProviderInterface::class),
                $c->get(AuthenticationServiceInterface::class),
                $c->get(RateLimiter::class),
                $c->get(UserServiceInterface::class),
            )
        );

        $container->singleton(
            DashboardController::class,
            static fn (Container $c): DashboardController => new DashboardController(
                $c->get(AdminView::class),
                $c->get(SessionInterface::class),
                $c->get(CsrfTokenManagerInterface::class),
                $c->get(ActorProviderInterface::class),
                $c->get(ContributorServiceInterface::class),
                $c->get(ContentQueryServiceInterface::class),
            )
        );

        $container->singleton(
            ContentController::class,
            static fn (Container $c): ContentController => new ContentController(
                $c->get(AdminView::class),
                $c->get(SessionInterface::class),
                $c->get(CsrfTokenManagerInterface::class),
                $c->get(ActorProviderInterface::class),
                $c->get(ContentServiceInterface::class),
                $c->get(ContentQueryServiceInterface::class),
                $c->get(ContributorServiceInterface::class),
                $c->get(EditorialWorkflowInterface::class),
                $c->get(TaxonomyServiceInterface::class),
                $c->get(SeoServiceInterface::class),
                $c->get(MediaServiceInterface::class),
                $c->get(BlockFormCompiler::class),
                $c->get(UserServiceInterface::class),
            )
        );

        $container->singleton(
            RevisionController::class,
            static fn (Container $c): RevisionController => new RevisionController(
                $c->get(AdminView::class),
                $c->get(SessionInterface::class),
                $c->get(CsrfTokenManagerInterface::class),
                $c->get(ActorProviderInterface::class),
                $c->get(RevisionServiceInterface::class),
                $c->get(ContentServiceInterface::class),
            )
        );

        $container->singleton(
            PasswordResetAdminController::class,
            static fn (Container $c): PasswordResetAdminController => new PasswordResetAdminController(
                $c->get(AdminView::class),
                $c->get(SessionInterface::class),
                $c->get(CsrfTokenManagerInterface::class),
                $c->get(ActorProviderInterface::class),
                $c->get(UserServiceInterface::class),
                $c->get(PasswordResetServiceInterface::class),
            )
        );

        $container->singleton(
            AdController::class,
            static fn (Container $c): AdController => new AdController(
                $c->get(AdminView::class),
                $c->get(SessionInterface::class),
                $c->get(CsrfTokenManagerInterface::class),
                $c->get(ActorProviderInterface::class),
                $c->get(AdvertisingServiceInterface::class),
                $c->get(PageCacheInterface::class),
            )
        );

        $container->singleton(
            AuditController::class,
            static fn (Container $c): AuditController => new AuditController(
                $c->get(AdminView::class),
                $c->get(SessionInterface::class),
                $c->get(CsrfTokenManagerInterface::class),
                $c->get(ActorProviderInterface::class),
                $c->get(AuditLoggerInterface::class),
            )
        );

        $container->singleton(
            PluginController::class,
            static fn (Container $c): PluginController => new PluginController(
                $c->get(AdminView::class),
                $c->get(SessionInterface::class),
                $c->get(CsrfTokenManagerInterface::class),
                $c->get(ActorProviderInterface::class),
                $c->get(PluginManagerInterface::class),
                $c->get(AuditLoggerInterface::class),
            )
        );

        $container->singleton(
            SiteController::class,
            static fn (Container $c): SiteController => new SiteController(
                $c->get(AdminView::class),
                $c->get(SessionInterface::class),
                $c->get(CsrfTokenManagerInterface::class),
                $c->get(ActorProviderInterface::class),
                $c->get(SiteServiceInterface::class),
                $c->get(RoleServiceInterface::class),
                $c->get(UserServiceInterface::class),
                $c->get(TaxonomyServiceInterface::class),
                $c->get(AuditLoggerInterface::class),
                $c->get(PageCacheInterface::class),
            )
        );

        $container->singleton(
            SettingsController::class,
            static fn (Container $c): SettingsController => new SettingsController(
                $c->get(AdminView::class),
                $c->get(SessionInterface::class),
                $c->get(CsrfTokenManagerInterface::class),
                $c->get(ActorProviderInterface::class),
                $c->get(SiteServiceInterface::class),
                $c->get(ThemeRegistryInterface::class),
                $c->get(PageCacheInterface::class),
                $c->get(AuditLoggerInterface::class),
            )
        );

        $container->singleton(
            RedirectController::class,
            static fn (Container $c): RedirectController => new RedirectController(
                $c->get(AdminView::class),
                $c->get(SessionInterface::class),
                $c->get(CsrfTokenManagerInterface::class),
                $c->get(ActorProviderInterface::class),
                $c->get(RedirectServiceInterface::class),
            )
        );

        $container->singleton(
            UserController::class,
            static fn (Container $c): UserController => new UserController(
                $c->get(AdminView::class),
                $c->get(SessionInterface::class),
                $c->get(CsrfTokenManagerInterface::class),
                $c->get(ActorProviderInterface::class),
                $c->get(UserServiceInterface::class),
                $c->get(RoleServiceInterface::class),
                $c->get(AuditLoggerInterface::class),
            )
        );

        $container->singleton(
            NotificationController::class,
            static fn (Container $c): NotificationController => new NotificationController(
                $c->get(AdminView::class),
                $c->get(SessionInterface::class),
                $c->get(CsrfTokenManagerInterface::class),
                $c->get(ActorProviderInterface::class),
                $c->get(NotificationServiceInterface::class),
            )
        );

        $container->singleton(
            CommentController::class,
            static fn (Container $c): CommentController => new CommentController(
                $c->get(AdminView::class),
                $c->get(SessionInterface::class),
                $c->get(CsrfTokenManagerInterface::class),
                $c->get(ActorProviderInterface::class),
                $c->get(CommentServiceInterface::class),
                $c->get(ContentServiceInterface::class),
            )
        );

        $container->singleton(
            MediaController::class,
            static fn (Container $c): MediaController => new MediaController(
                $c->get(AdminView::class),
                $c->get(SessionInterface::class),
                $c->get(CsrfTokenManagerInterface::class),
                $c->get(ActorProviderInterface::class),
                $c->get(MediaServiceInterface::class),
            )
        );
    }

    public function boot(Container $container): void
    {
        /** @var Router $router */
        $router = $container->get(Router::class);

        // Error handling is outermost so a failure while resolving the tenant still renders a
        // page; context next so everything below has the site and the actor.
        $chain = [
            AdminErrorMiddleware::class,
            AdminContextMiddleware::class,
            AdminAuthMiddleware::class,
        ];

        $this->route($container, $router, 'GET', '/admin/login', AuthController::class, 'showLogin', $chain);
        $this->route($container, $router, 'POST', '/admin/login', AuthController::class, 'login', $chain);
        $this->route($container, $router, 'POST', '/admin/logout', AuthController::class, 'logout', $chain);

        $this->route($container, $router, 'GET', '/admin/password', AuthController::class, 'showPassword', $chain);
        $this->route($container, $router, 'POST', '/admin/password', AuthController::class, 'changePassword', $chain);

        $this->route($container, $router, 'GET', '/admin', DashboardController::class, 'index', $chain);

        $this->route($container, $router, 'GET', '/admin/content/{id:\d+}/revisions', RevisionController::class, 'index', $chain);
        $this->route($container, $router, 'GET', '/admin/content/{id:\d+}/revisions/{revision:\d+}', RevisionController::class, 'diff', $chain);
        $this->route($container, $router, 'POST', '/admin/content/{id:\d+}/revisions/restore', RevisionController::class, 'restore', $chain);
        $this->route($container, $router, 'POST', '/admin/users/{id:\d+}/reset-password', PasswordResetAdminController::class, 'issue', $chain);

        $this->route($container, $router, 'GET', '/admin/ads', AdController::class, 'index', $chain);
        $this->route($container, $router, 'POST', '/admin/ads', AdController::class, 'store', $chain);
        $this->route($container, $router, 'POST', '/admin/ads/toggle', AdController::class, 'toggle', $chain);
        $this->route($container, $router, 'POST', '/admin/ads/delete', AdController::class, 'destroy', $chain);

        $this->route($container, $router, 'GET', '/admin/plugins', PluginController::class, 'index', $chain);
        $this->route($container, $router, 'POST', '/admin/plugins/install', PluginController::class, 'install', $chain);
        $this->route($container, $router, 'POST', '/admin/plugins/uninstall', PluginController::class, 'uninstall', $chain);
        $this->route($container, $router, 'POST', '/admin/plugins/update', PluginController::class, 'update', $chain);
        $this->route($container, $router, 'POST', '/admin/plugins/activate', PluginController::class, 'activate', $chain);
        $this->route($container, $router, 'POST', '/admin/plugins/deactivate', PluginController::class, 'deactivate', $chain);

        $this->route($container, $router, 'GET', '/admin/audit', AuditController::class, 'index', $chain);
        $this->route($container, $router, 'GET', '/admin/sites', SiteController::class, 'index', $chain);
        $this->route($container, $router, 'POST', '/admin/sites', SiteController::class, 'store', $chain);
        $this->route($container, $router, 'POST', '/admin/sites/status', SiteController::class, 'status', $chain);
        $this->route($container, $router, 'POST', '/admin/sites/domain', SiteController::class, 'addDomain', $chain);
        $this->route($container, $router, 'POST', '/admin/sites/domain/remove', SiteController::class, 'removeDomain', $chain);

        $this->route($container, $router, 'GET', '/admin/settings', SettingsController::class, 'index', $chain);
        $this->route($container, $router, 'POST', '/admin/settings', SettingsController::class, 'save', $chain);
        $this->route($container, $router, 'POST', '/admin/settings/theme', SettingsController::class, 'theme', $chain);

        $this->route($container, $router, 'GET', '/admin/redirects', RedirectController::class, 'index', $chain);
        $this->route($container, $router, 'POST', '/admin/redirects', RedirectController::class, 'store', $chain);
        $this->route($container, $router, 'POST', '/admin/redirects/import', RedirectController::class, 'import', $chain);
        $this->route($container, $router, 'POST', '/admin/redirects/delete', RedirectController::class, 'destroy', $chain);

        $this->route($container, $router, 'GET', '/admin/users', UserController::class, 'index', $chain);
        $this->route($container, $router, 'GET', '/admin/users/new', UserController::class, 'create', $chain);
        $this->route($container, $router, 'POST', '/admin/users', UserController::class, 'store', $chain);
        $this->route($container, $router, 'GET', '/admin/users/{id:\d+}', UserController::class, 'edit', $chain);
        $this->route($container, $router, 'POST', '/admin/users/{id:\d+}', UserController::class, 'save', $chain);
        $this->route($container, $router, 'POST', '/admin/users/{id:\d+}/delete', UserController::class, 'delete', $chain);

        $this->route($container, $router, 'GET', '/admin/notifications', NotificationController::class, 'index', $chain);
        $this->route($container, $router, 'POST', '/admin/notifications/read-all', NotificationController::class, 'markAllRead', $chain);
        $this->route($container, $router, 'POST', '/admin/notifications/{id:\d+}/read', NotificationController::class, 'markRead', $chain);
        $this->route($container, $router, 'GET', '/admin/notifications/preferences', NotificationController::class, 'preferences', $chain);
        $this->route($container, $router, 'POST', '/admin/notifications/preferences', NotificationController::class, 'updatePreferences', $chain);

        $this->route($container, $router, 'GET', '/admin/comments', CommentController::class, 'index', $chain);
        $this->route($container, $router, 'POST', '/admin/comments/{id:\d+}/approve', CommentController::class, 'approve', $chain);
        $this->route($container, $router, 'POST', '/admin/comments/{id:\d+}/spam', CommentController::class, 'spam', $chain);
        $this->route($container, $router, 'POST', '/admin/comments/{id:\d+}/trash', CommentController::class, 'trash', $chain);
        $this->route($container, $router, 'POST', '/admin/comments/{id:\d+}/delete', CommentController::class, 'destroy', $chain);

        $this->route($container, $router, 'GET', '/admin/media', MediaController::class, 'index', $chain);
        $this->route($container, $router, 'POST', '/admin/media', MediaController::class, 'store', $chain);
        $this->route($container, $router, 'POST', '/admin/media/{id:\d+}/delete', MediaController::class, 'destroy', $chain);

        $this->route($container, $router, 'GET', '/admin/content', ContentController::class, 'index', $chain);
        $this->route($container, $router, 'GET', '/admin/content/new', ContentController::class, 'create', $chain);
        $this->route($container, $router, 'POST', '/admin/content', ContentController::class, 'store', $chain);
        $this->route($container, $router, 'GET', '/admin/content/{id:\d+}', ContentController::class, 'edit', $chain);
        $this->route($container, $router, 'POST', '/admin/content/{id:\d+}/save', ContentController::class, 'update', $chain);
        $this->route($container, $router, 'POST', '/admin/content/{id:\d+}/submit', ContentController::class, 'submit', $chain);
        $this->route($container, $router, 'POST', '/admin/content/{id:\d+}/transition', ContentController::class, 'transition', $chain);
        $this->route($container, $router, 'GET', '/admin/content/{id:\d+}/review', ContentController::class, 'review', $chain);
        $this->route($container, $router, 'POST', '/admin/content/{id:\d+}/terms', ContentController::class, 'terms', $chain);
        $this->route($container, $router, 'POST', '/admin/content/{id:\d+}/seo', ContentController::class, 'seo', $chain);
        $this->route($container, $router, 'POST', '/admin/content/{id:\d+}/delete', ContentController::class, 'delete', $chain);
        $this->route($container, $router, 'POST', '/admin/content/{id:\d+}/schedule', ContentController::class, 'schedule', $chain);
        $this->route($container, $router, 'POST', '/admin/content/{id:\d+}/featured', ContentController::class, 'featured', $chain);
        $this->route($container, $router, 'POST', '/admin/content/{id:\d+}/block-image', ContentController::class, 'blockImage', $chain);
    }

    /**
     * Bind a controller action to a verb.
     *
     * The action is named explicitly and resolved lazily, so a typo fails on the first
     * request instead of silently wiring the wrong method.
     *
     * @param list<string> $middleware
     */
    private function route(
        Container $container,
        Router $router,
        string $method,
        string $path,
        string $controller,
        string $action,
        array $middleware,
    ): void {
        $router->add($method, $path, static function (\CMS\Core\Http\Request $request) use ($container, $controller, $action): \CMS\Core\Http\Response {
            $instance = $container->get($controller);

            if (!is_object($instance) || !method_exists($instance, $action)) {
                throw \CMS\Core\Http\HttpException::internalError('Admin action not found: ' . $controller . '@' . $action);
            }

            $result = $instance->{$action}($request);

            if (!$result instanceof \CMS\Core\Http\Response) {
                throw \CMS\Core\Http\HttpException::internalError('Admin action must return a Response.');
            }

            return $result;
        })->middleware($middleware);
    }
}
