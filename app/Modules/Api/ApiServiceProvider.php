<?php

declare(strict_types=1);

namespace CMS\Modules\Api;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Core\Logging\LoggerInterface;
use CMS\Core\Routing\Router;
use CMS\Core\Security\RateLimiter;
use CMS\Modules\Api\Http\Controller\AuthController;
use CMS\Modules\Api\Http\Controller\ContentController;
use CMS\Modules\Api\Http\Controller\TaxonomyController;
use CMS\Modules\Api\Http\Middleware\ApiContextMiddleware;
use CMS\Modules\Api\Http\Middleware\CsrfMiddleware;
use CMS\Modules\Api\Http\Middleware\RateLimitMiddleware;
use CMS\Modules\Cache\CacheServiceProvider;
use CMS\Modules\Advertising\Contracts\AdvertisingServiceInterface;
use CMS\Modules\Api\Http\Controller\AdController;
use CMS\Modules\Api\Http\Controller\CommentController;
use CMS\Modules\Api\Http\Controller\MeController;
use CMS\Modules\Api\Http\Controller\MediaController;
use CMS\Modules\Api\Http\Controller\RedirectController;
use CMS\Modules\Api\Http\Controller\MigrationController;
use CMS\Modules\Api\Http\Controller\NotificationController;
use CMS\Modules\Api\Http\Controller\PluginController;
use CMS\Modules\Api\Http\Controller\SeoController;
use CMS\Modules\Api\Http\Controller\SettingsController;
use CMS\Modules\Api\Http\Controller\ThemeController;
use CMS\Modules\Api\Http\Controller\UserController;
use CMS\Modules\Api\Resources\MediaResource;
use CMS\Modules\Api\Resources\RedirectResource;
use CMS\Modules\Api\Resources\SeoResource;
use CMS\Modules\Api\Resources\ThemeResource;
use CMS\Modules\Identity\Contracts\RoleServiceInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Migration\Contracts\MigrationServiceInterface;
use CMS\Modules\Notification\Contracts\NotificationServiceInterface;
use CMS\Modules\Plugin\Contracts\PluginManagerInterface;
use CMS\Modules\Search\Contracts\SearchServiceInterface;
use CMS\Modules\Seo\Contracts\RedirectServiceInterface;
use CMS\Modules\Seo\Contracts\SeoServiceInterface;
use CMS\Modules\Site\Contracts\SettingsServiceInterface;
use CMS\Modules\Theme\Contracts\ThemeRegistryInterface;
use CMS\Shared\Contracts\ConfigInterface;
use CMS\Modules\Api\Resources\ContentResource;
use CMS\Modules\Api\Resources\ContentSummaryResource;
use CMS\Modules\Api\Resources\RevisionResource;
use CMS\Modules\Api\Resources\TermResource;
use CMS\Modules\Api\Resources\UserResource;
use CMS\Modules\Api\Resources\WorkflowLogResource;
use CMS\Modules\Comment\Contracts\CommentServiceInterface;
use CMS\Modules\Contribution\Contracts\ContributorServiceInterface;
use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Contracts\ContentTypeRegistryInterface;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;
use CMS\Modules\Editorial\Contracts\EditorialWorkflowInterface;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Identity\Contracts\AuthenticationServiceInterface;
use CMS\Modules\Identity\Contracts\PasswordResetServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Modules\Revision\Contracts\RevisionServiceInterface;
use CMS\Modules\Site\Contracts\SiteResolverInterface;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;

/**
 * Wires the REST API.
 *
 * Every `/api/v1` route carries `ApiContextMiddleware`, so the tenant is resolved
 * from the host and the actor from the session before a controller runs. A route
 * registered without that middleware fails loudly rather than silently skipping
 * tenant isolation.
 */
final class ApiServiceProvider implements ServiceProviderInterface
{
    /**
     * Route middleware is resolved from the container by key, so a limiter with a
     * specific policy needs its own binding: an object cannot be listed directly
     * in a route's middleware array.
     */
    private const RATE_LIMIT_LOGIN = 'api.rate_limit.login';
    private const RATE_LIMIT_FORGOT_PASSWORD = 'api.rate_limit.forgot_password';
    private const RATE_LIMIT_RESET_PASSWORD = 'api.rate_limit.reset_password';
    private const RATE_LIMIT_COMMENT = 'api.rate_limit.comment';

    public function register(Container $container): void
    {
        // Resources
        $container->singleton(ContentResource::class, static fn (Container $c): ContentResource => new ContentResource(
            $c->get(UrlGeneratorInterface::class)
        ));
        $container->singleton(ContentSummaryResource::class, static fn (Container $c): ContentSummaryResource => new ContentSummaryResource(
            $c->get(UrlGeneratorInterface::class)
        ));
        $container->singleton(TermResource::class, static fn (): TermResource => new TermResource());
        $container->singleton(UserResource::class, static fn (): UserResource => new UserResource());
        $container->singleton(RevisionResource::class, static fn (): RevisionResource => new RevisionResource());
        $container->singleton(WorkflowLogResource::class, static fn (): WorkflowLogResource => new WorkflowLogResource());

        // Middleware
        $container->singleton(CsrfMiddleware::class, static fn (Container $c): CsrfMiddleware => new CsrfMiddleware(
            $c->get(CsrfTokenManagerInterface::class)
        ));

        $container->singleton(ApiContextMiddleware::class, static fn (Container $c): ApiContextMiddleware => new ApiContextMiddleware(
            $c->get(SiteResolverInterface::class),
            $c->get(AuthenticationServiceInterface::class),
            $c->get(ActorProviderInterface::class),
        ));

        // Redis is forbidden on the production target, so counters live on the
        // filesystem beside the cache. The class owns no policy or location: both
        // arrive from here, which is where configuration is read.
        $container->singleton(RateLimiter::class, static function (Container $c): RateLimiter {
            /** @var ConfigInterface $config */
            $config = $c->get(ConfigInterface::class);

            $directory = (string) $config->get('security.rate_limit_path', '');

            if ($directory === '') {
                $directory = CacheServiceProvider::cacheRoot($config) . DIRECTORY_SEPARATOR . 'rate-limits';
            }

            return new RateLimiter($directory, $c->get(LoggerInterface::class));
        });

        $container->singleton(
            self::RATE_LIMIT_LOGIN,
            static fn (Container $c): RateLimitMiddleware => new RateLimitMiddleware(
                $c->get(RateLimiter::class),
                5,
                300,
            )
        );

        $container->singleton(
            self::RATE_LIMIT_FORGOT_PASSWORD,
            static fn (Container $c): RateLimitMiddleware => new RateLimitMiddleware(
                $c->get(RateLimiter::class),
                3,
                300,
            )
        );

        $container->singleton(
            self::RATE_LIMIT_RESET_PASSWORD,
            static fn (Container $c): RateLimitMiddleware => new RateLimitMiddleware(
                $c->get(RateLimiter::class),
                5,
                300,
            )
        );

        $container->singleton(
            self::RATE_LIMIT_COMMENT,
            static fn (Container $c): RateLimitMiddleware => new RateLimitMiddleware(
                $c->get(RateLimiter::class),
                10,
                60,
            )
        );

        // Controllers
        $container->singleton(AuthController::class, static fn (Container $c): AuthController => new AuthController(
            $c->get(AuthenticationServiceInterface::class),
            $c->get(PasswordResetServiceInterface::class),
            $c->get(UserServiceInterface::class),
            $c->get(UserResource::class),
            $c->get(CsrfTokenManagerInterface::class),
            $c->get(RateLimiter::class),
        ));

        $container->singleton(ContentController::class, static fn (Container $c): ContentController => new ContentController(
            $c->get(ContentServiceInterface::class),
            $c->get(ContentQueryServiceInterface::class),
            $c->get(ContentTypeRegistryInterface::class),
            $c->get(EditorialWorkflowInterface::class),
            $c->get(RevisionServiceInterface::class),
            $c->get(SearchServiceInterface::class),
            $c->get(TaxonomyServiceInterface::class),
            $c->get(ContentResource::class),
            $c->get(ContentSummaryResource::class),
            $c->get(RevisionResource::class),
            $c->get(WorkflowLogResource::class),
        ));

        $container->singleton(TaxonomyController::class, static fn (Container $c): TaxonomyController => new TaxonomyController(
            $c->get(TaxonomyServiceInterface::class),
            $c->get(TermResource::class),
        ));

        $container->singleton(MediaResource::class, static fn (Container $c): MediaResource => new MediaResource(
            (string) $c->get(ConfigInterface::class)->get('app.url', '')
        ));

        $container->singleton(SeoResource::class, static fn (): SeoResource => new SeoResource());
        $container->singleton(RedirectResource::class, static fn (): RedirectResource => new RedirectResource());
        $container->singleton(ThemeResource::class, static fn (): ThemeResource => new ThemeResource());

        $container->singleton(MediaController::class, static fn (Container $c): MediaController => new MediaController(
            $c->get(MediaServiceInterface::class),
            $c->get(MediaResource::class),
        ));

        $container->singleton(SeoController::class, static fn (Container $c): SeoController => new SeoController(
            $c->get(SeoServiceInterface::class),
            $c->get(ContentServiceInterface::class),
            $c->get(SeoResource::class),
        ));

        $container->singleton(RedirectController::class, static fn (Container $c): RedirectController => new RedirectController(
            $c->get(RedirectServiceInterface::class),
            $c->get(RedirectResource::class),
        ));

        $container->singleton(SettingsController::class, static fn (Container $c): SettingsController => new SettingsController(
            $c->get(SettingsServiceInterface::class),
        ));

        $container->singleton(ThemeController::class, static fn (Container $c): ThemeController => new ThemeController(
            $c->get(ThemeRegistryInterface::class),
            $c->get(ThemeResource::class),
        ));

        $container->singleton(CommentController::class, static fn (Container $c): CommentController => new CommentController(
            $c->get(CommentServiceInterface::class)
        ));

        $container->singleton(MigrationController::class, static fn (Container $c): MigrationController => new MigrationController(
            $c->get(MigrationServiceInterface::class)
        ));

        $container->singleton(NotificationController::class, static fn (Container $c): NotificationController => new NotificationController(
            $c->get(NotificationServiceInterface::class)
        ));

        $container->singleton(AdController::class, static fn (Container $c): AdController => new AdController(
            $c->get(AdvertisingServiceInterface::class)
        ));

        $container->singleton(MeController::class, static fn (Container $c): MeController => new MeController(
            $c->get(ContributorServiceInterface::class),
            $c->get(ContentResource::class),
            $c->get(ContentSummaryResource::class),
            $c->get(WorkflowLogResource::class),
        ));

        $container->singleton(PluginController::class, static fn (Container $c): PluginController => new PluginController(
            $c->get(PluginManagerInterface::class)
        ));

        $container->singleton(UserController::class, static fn (Container $c): UserController => new UserController(
            $c->get(UserServiceInterface::class),
            $c->get(RoleServiceInterface::class),
            $c->get(UserResource::class),
        ));
    }

    public function boot(Container $container): void
    {
        /** @var Router $router */
        $router = $container->get(Router::class);

        // Every API route resolves the tenant and the actor.
        $read = [ApiContextMiddleware::class];

        // Every mutation additionally requires a CSRF token. Applied by default
        // rather than per route, so a new write endpoint cannot be added without it.
        $write = [ApiContextMiddleware::class, CsrfMiddleware::class];

        // Throttles are appended after CSRF. A request rejected for a missing
        // token never reaches the abuse target, so it must not spend the
        // allowance of a legitimate visitor who happens to hold a stale token.
        $loginWrite = [...$write, self::RATE_LIMIT_LOGIN];
        $forgotPasswordWrite = [...$write, self::RATE_LIMIT_FORGOT_PASSWORD];
        $resetPasswordWrite = [...$write, self::RATE_LIMIT_RESET_PASSWORD];
        $commentWrite = [...$write, self::RATE_LIMIT_COMMENT];

        // ---- Authentication ----
        $this->route($container, $router, 'GET', '/api/v1/auth/csrf', AuthController::class, 'csrf', $read);
        $this->route($container, $router, 'POST', '/api/v1/auth/login', AuthController::class, 'login', $loginWrite);
        $this->route($container, $router, 'POST', '/api/v1/auth/logout', AuthController::class, 'logout', $write);
        $this->route($container, $router, 'GET', '/api/v1/auth/me', AuthController::class, 'me', $read);
        $this->route($container, $router, 'POST', '/api/v1/auth/forgot-password', AuthController::class, 'forgotPassword', $forgotPasswordWrite);
        $this->route($container, $router, 'POST', '/api/v1/auth/reset-password', AuthController::class, 'resetPassword', $resetPasswordWrite);

        // ---- Content ----
        $this->route($container, $router, 'GET', '/api/v1/content', ContentController::class, 'index', $read);
        $this->route($container, $router, 'POST', '/api/v1/content', ContentController::class, 'store', $write);
        $this->route($container, $router, 'GET', '/api/v1/content/{id:\d+}', ContentController::class, 'show', $read);
        $this->route($container, $router, 'PATCH', '/api/v1/content/{id:\d+}', ContentController::class, 'update', $write);
        $this->route($container, $router, 'DELETE', '/api/v1/content/{id:\d+}', ContentController::class, 'destroy', $write);

        // Type-pinned collections required by the task pack.
        $this->route($container, $router, 'GET', '/api/v1/posts', ContentController::class, 'posts', $read);
        $this->route($container, $router, 'GET', '/api/v1/pages', ContentController::class, 'pages', $read);

        // ---- Workflow ----
        $this->route($container, $router, 'POST', '/api/v1/content/{id:\d+}/transitions', ContentController::class, 'transition', $write);
        $this->route($container, $router, 'GET', '/api/v1/content/{id:\d+}/transitions', ContentController::class, 'transitions', $read);
        $this->route($container, $router, 'GET', '/api/v1/content/{id:\d+}/history', ContentController::class, 'history', $read);

        // ---- Revisions & autosave ----
        $this->route($container, $router, 'GET', '/api/v1/content/{id:\d+}/revisions', ContentController::class, 'revisions', $read);
        $this->route($container, $router, 'POST', '/api/v1/content/{id:\d+}/revisions', ContentController::class, 'snapshot', $write);
        $this->route($container, $router, 'POST', '/api/v1/content/{id:\d+}/autosave', ContentController::class, 'autosave', $write);

        // ---- Content terms ----
        $this->route($container, $router, 'GET', '/api/v1/content/{id:\d+}/terms', ContentController::class, 'terms', $read);
        $this->route($container, $router, 'PUT', '/api/v1/content/{id:\d+}/terms', ContentController::class, 'syncTerms', $write);

        // ---- Taxonomies ----
        $this->route($container, $router, 'GET', '/api/v1/taxonomies/{taxonomy}/terms', TaxonomyController::class, 'index', $read);
        $this->route($container, $router, 'POST', '/api/v1/taxonomies/{taxonomy}/terms', TaxonomyController::class, 'store', $write);
        $this->route($container, $router, 'DELETE', '/api/v1/taxonomies/terms/{id:\d+}', TaxonomyController::class, 'destroy', $write);

        // ---- Search ----
        $this->route($container, $router, 'GET', '/api/v1/search', ContentController::class, 'search', $read);

        // ---- Comments ----
        $this->route($container, $router, 'GET', '/api/v1/content/{id:\d+}/comments', CommentController::class, 'index', $read);
        $this->route($container, $router, 'POST', '/api/v1/content/{id:\d+}/comments', CommentController::class, 'store', $commentWrite);
        $this->route($container, $router, 'GET', '/api/v1/comments', CommentController::class, 'queue', $read);
        $this->route($container, $router, 'POST', '/api/v1/comments/{id:\d+}/approve', CommentController::class, 'approve', $write);
        $this->route($container, $router, 'POST', '/api/v1/comments/{id:\d+}/spam', CommentController::class, 'spam', $write);
        $this->route($container, $router, 'POST', '/api/v1/comments/{id:\d+}/trash', CommentController::class, 'trash', $write);
        $this->route($container, $router, 'DELETE', '/api/v1/comments/{id:\d+}', CommentController::class, 'destroy', $write);

        // ---- Media ----
        $this->route($container, $router, 'GET', '/api/v1/media', MediaController::class, 'index', $read);
        $this->route($container, $router, 'POST', '/api/v1/media', MediaController::class, 'store', $write);
        $this->route($container, $router, 'GET', '/api/v1/media/{id:\d+}', MediaController::class, 'show', $read);
        $this->route($container, $router, 'PATCH', '/api/v1/media/{id:\d+}', MediaController::class, 'update', $write);
        $this->route($container, $router, 'DELETE', '/api/v1/media/{id:\d+}', MediaController::class, 'destroy', $write);

        // ---- SEO ----
        $this->route($container, $router, 'GET', '/api/v1/content/{id:\d+}/seo', SeoController::class, 'show', $read);
        $this->route($container, $router, 'PUT', '/api/v1/content/{id:\d+}/seo', SeoController::class, 'store', $write);

        // ---- Redirects ----
        $this->route($container, $router, 'GET', '/api/v1/redirects', RedirectController::class, 'index', $read);
        $this->route($container, $router, 'POST', '/api/v1/redirects', RedirectController::class, 'store', $write);
        $this->route($container, $router, 'DELETE', '/api/v1/redirects', RedirectController::class, 'destroy', $write);

        // ---- Settings ----
        $this->route($container, $router, 'GET', '/api/v1/settings', SettingsController::class, 'index', $read);
        $this->route($container, $router, 'PUT', '/api/v1/settings', SettingsController::class, 'update', $write);

        // ---- Themes ----
        $this->route($container, $router, 'GET', '/api/v1/themes', ThemeController::class, 'index', $read);
        $this->route($container, $router, 'GET', '/api/v1/themes/active', ThemeController::class, 'active', $read);
        $this->route($container, $router, 'POST', '/api/v1/themes/{name}/activate', ThemeController::class, 'activate', $write);

        // ---- Contributor workspace (14C) ----
        // Scoped to the authenticated user: no author id is ever accepted.
        $this->route($container, $router, 'GET', '/api/v1/me/content', MeController::class, 'drafts', $read);
        $this->route($container, $router, 'GET', '/api/v1/me/content/published', MeController::class, 'published', $read);
        $this->route($container, $router, 'POST', '/api/v1/me/content', MeController::class, 'store', $write);
        $this->route($container, $router, 'PATCH', '/api/v1/me/content/{id:\d+}', MeController::class, 'update', $write);
        $this->route($container, $router, 'POST', '/api/v1/me/content/{id:\d+}/submit', MeController::class, 'submit', $write);
        $this->route($container, $router, 'GET', '/api/v1/me/content/{id:\d+}/history', MeController::class, 'history', $read);

        // ---- WordPress migration (Agent 15) ----
        $this->route($container, $router, 'GET', '/api/v1/migration/analyze', MigrationController::class, 'analyze', $read);
        $this->route($container, $router, 'GET', '/api/v1/migration/plan', MigrationController::class, 'plan', $read);
        $this->route($container, $router, 'POST', '/api/v1/migration/batch', MigrationController::class, 'batch', $write);
        $this->route($container, $router, 'GET', '/api/v1/migration/report', MigrationController::class, 'report', $read);
        $this->route($container, $router, 'GET', '/api/v1/migration/logs', MigrationController::class, 'logs', $read);

        // ---- Notifications (14E) ----
        // The inbox is scoped to the authenticated user; an id cannot address someone
        // else's notification.
        $this->route($container, $router, 'GET', '/api/v1/me/notifications', NotificationController::class, 'index', $read);
        $this->route($container, $router, 'POST', '/api/v1/me/notifications/{id:\d+}/read', NotificationController::class, 'markRead', $write);
        $this->route($container, $router, 'POST', '/api/v1/me/notifications/read-all', NotificationController::class, 'markAllRead', $write);
        $this->route($container, $router, 'GET', '/api/v1/notifications/preferences', NotificationController::class, 'preferences', $read);
        $this->route($container, $router, 'PUT', '/api/v1/notifications/preferences', NotificationController::class, 'updatePreferences', $write);

        // ---- Advertising ----
        $this->route($container, $router, 'GET', '/api/v1/ads', AdController::class, 'index', $read);
        $this->route($container, $router, 'POST', '/api/v1/ads', AdController::class, 'store', $write);
        $this->route($container, $router, 'PATCH', '/api/v1/ads/{id:\d+}', AdController::class, 'update', $write);
        $this->route($container, $router, 'DELETE', '/api/v1/ads/{id:\d+}', AdController::class, 'destroy', $write);

        // ---- Plugins ----
        $this->route($container, $router, 'GET', '/api/v1/plugins', PluginController::class, 'index', $read);
        $this->route($container, $router, 'POST', '/api/v1/plugins/{name}/install', PluginController::class, 'install', $write);
        $this->route($container, $router, 'DELETE', '/api/v1/plugins/{name}', PluginController::class, 'uninstall', $write);
        $this->route($container, $router, 'POST', '/api/v1/plugins/{name}/activate', PluginController::class, 'activate', $write);
        $this->route($container, $router, 'POST', '/api/v1/plugins/{name}/deactivate', PluginController::class, 'deactivate', $write);

        // ---- Users and roles ----
        $this->route($container, $router, 'GET', '/api/v1/users', UserController::class, 'index', $read);
        $this->route($container, $router, 'POST', '/api/v1/users', UserController::class, 'store', $write);
        $this->route($container, $router, 'GET', '/api/v1/users/{id:\d+}', UserController::class, 'show', $read);
        $this->route($container, $router, 'PATCH', '/api/v1/users/{id:\d+}', UserController::class, 'update', $write);
        $this->route($container, $router, 'DELETE', '/api/v1/users/{id:\d+}', UserController::class, 'destroy', $write);
        $this->route($container, $router, 'GET', '/api/v1/users/{id:\d+}/roles', UserController::class, 'roles', $read);
        $this->route($container, $router, 'PUT', '/api/v1/users/{id:\d+}/roles', UserController::class, 'syncRoles', $write);
    }

    /**
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
        // The controller is resolved lazily from the container and the action is
        // named explicitly, so a typo fails on first request rather than silently
        // binding the wrong method to a verb.
        $router->add($method, $path, static function (Request $request) use ($container, $controller, $action): Response {
            $instance = $container->get($controller);

            if (!is_object($instance) || !method_exists($instance, $action)) {
                throw HttpException::internalError('API action not found: ' . $controller . '@' . $action);
            }

            $result = $instance->{$action}($request);

            if (!$result instanceof Response) {
                throw HttpException::internalError('API action must return a Response.');
            }

            return $result;
        })->middleware($middleware);
    }
}




