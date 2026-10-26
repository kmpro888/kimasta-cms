<?php

declare(strict_types=1);

namespace CMS\Modules\Identity;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Core\Database\Connection;
use CMS\Core\Database\ConnectionFactory;
use CMS\Modules\Identity\Auth\ActorProvider;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Identity\Contracts\AuthenticationServiceInterface;
use CMS\Modules\Identity\Contracts\AuthorizationServiceInterface;
use CMS\Modules\Identity\Contracts\PasswordResetServiceInterface;
use CMS\Modules\Identity\Contracts\ExtraCapabilityProviderInterface;
use CMS\Modules\Identity\Contracts\RoleServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Modules\Identity\Repository\CapabilityRepositoryInterface;
use CMS\Modules\Identity\Repository\PasswordResetRepositoryInterface;
use CMS\Modules\Identity\Repository\PdoCapabilityRepository;
use CMS\Modules\Identity\Repository\PdoPasswordResetRepository;
use CMS\Modules\Identity\Repository\PdoRoleRepository;
use CMS\Modules\Identity\Repository\PdoUserRepository;
use CMS\Modules\Identity\Repository\RoleRepositoryInterface;
use CMS\Modules\Identity\Repository\UserRepositoryInterface;
use CMS\Modules\Identity\Service\AuthenticationService;
use CMS\Modules\Identity\Service\AuthorizationService;
use CMS\Modules\Identity\Service\PasswordResetService;
use CMS\Modules\Identity\Service\RoleService;
use CMS\Modules\Identity\Service\UserService;
use CMS\Modules\Identity\Session\NativeSession;
use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\ConfigInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * Wires the Identity module into the container.
 *
 * Registration builds closures only; no I/O happens until a service is
 * actually resolved, so booting the application for an anonymous cached page
 * still costs nothing here.
 */
final class IdentityServiceProvider implements ServiceProviderInterface
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
            UserRepositoryInterface::class,
            static fn (Container $c): UserRepositoryInterface => new PdoUserRepository($c->get(Connection::class))
        );

        $container->singleton(
            RoleRepositoryInterface::class,
            static fn (Container $c): RoleRepositoryInterface => new PdoRoleRepository($c->get(Connection::class))
        );

        $container->singleton(
            CapabilityRepositoryInterface::class,
            static fn (Container $c): CapabilityRepositoryInterface => new PdoCapabilityRepository($c->get(Connection::class))
        );

        $container->singleton(
            PasswordResetRepositoryInterface::class,
            static fn (Container $c): PasswordResetRepositoryInterface => new PdoPasswordResetRepository($c->get(Connection::class))
        );

        $container->singleton(SessionInterface::class, static function (Container $c): SessionInterface {
            /** @var ConfigInterface $config */
            $config = $c->get(ConfigInterface::class);

            return new NativeSession(
                lifetimeMinutes: (int) $config->get('security.session_lifetime', 120),
                secure: (bool) $config->get('security.cookie_secure', true) && self::requestIsHttps(),
            );
        });

        $container->singleton(ActorProviderInterface::class, static function (Container $c): ActorProviderInterface {
            return new ActorProvider(
                $c->get(UserRepositoryInterface::class),
                $c->get(RoleRepositoryInterface::class),
            );
        });

        $container->singleton(
            AuthenticationServiceInterface::class,
            static fn (Container $c): AuthenticationServiceInterface => new AuthenticationService(
                $c->get(UserRepositoryInterface::class),
                $c->get(SessionInterface::class),
                $c->get(ClockInterface::class),
            )
        );

        $container->singleton(
            AuthorizationServiceInterface::class,
            static fn (Container $c): AuthorizationServiceInterface => new AuthorizationService(
                $c->get(RoleRepositoryInterface::class),
            )
        );

        $container->singleton(
            PasswordResetServiceInterface::class,
            static fn (Container $c): PasswordResetServiceInterface => new PasswordResetService(
                $c->get(UserRepositoryInterface::class),
                $c->get(PasswordResetRepositoryInterface::class),
                $c->get(ClockInterface::class),
                60,
            )
        );

        $container->singleton(
            UserServiceInterface::class,
            static fn (Container $c): UserServiceInterface => new UserService(
                $c->get(UserRepositoryInterface::class),
                $c->get(ClockInterface::class),
            )
        );

        $container->singleton(
            RoleServiceInterface::class,
            static fn (Container $c): RoleServiceInterface => new RoleService(
                $c->get(RoleRepositoryInterface::class),
                $c->get(CapabilityRepositoryInterface::class),
                $c->get(UserRepositoryInterface::class),
                // Optional seam: present only when a module contributes capabilities
                // (the plugin platform does). Resolved lazily so Identity does not
                // depend on the contributor existing.
                $c->has(ExtraCapabilityProviderInterface::class)
                    ? $c->get(ExtraCapabilityProviderInterface::class)
                    : null,
            )
        );
    }

    public function boot(Container $container): void
    {
        // No routes yet: login/reset endpoints are thin controllers that Agent 13
        // exposes against these services.
    }

    /**
     * Is the current request served over HTTPS?
     *
     * The composition root is the one place allowed to look at the server
     * environment; services receive an explicit boolean instead.
     */
    private static function requestIsHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? null;

        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        return (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    }
}
