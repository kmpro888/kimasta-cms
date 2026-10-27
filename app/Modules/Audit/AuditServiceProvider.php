<?php

declare(strict_types=1);

namespace CMS\Modules\Audit;

use CMS\Core\Container\Container;
use CMS\Core\Database\Connection;
use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Modules\Audit\Contracts\AuditLoggerInterface;
use CMS\Modules\Audit\Repository\AuditLogRepositoryInterface;
use CMS\Modules\Audit\Repository\PdoAuditLogRepository;
use CMS\Modules\Audit\Service\AuditLogger;
use CMS\Modules\Identity\Contracts\UserServiceInterface;

/**
 * Wires the audit trail.
 *
 * Small on purpose: one table, one writer, one reader. The interesting decisions live in the
 * repository (append and read only) and in `AuditLogger` (never throw).
 */
final class AuditServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(
            AuditLogRepositoryInterface::class,
            static fn (Container $c): AuditLogRepositoryInterface => new PdoAuditLogRepository(
                $c->get(Connection::class)
            )
        );

        $container->singleton(
            AuditLoggerInterface::class,
            static fn (Container $c): AuditLoggerInterface => new AuditLogger(
                $c->get(AuditLogRepositoryInterface::class),
                $c->get(UserServiceInterface::class),
            )
        );
    }

    /**
     * Nothing to subscribe to: the trail is written by the actions that call it, not by events.
     *
     * That is deliberate — an event-driven audit would miss anything that does not dispatch an
     * event, and the calls are few enough to be visible at the call site.
     */
    public function boot(Container $container): void
    {
    }
}
