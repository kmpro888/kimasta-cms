<?php

declare(strict_types=1);

namespace CMS\Modules\Notification;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Core\Database\Connection;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Identity\Contracts\RoleServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Modules\Notification\Contracts\MailTransportInterface;
use CMS\Modules\Notification\Contracts\NotificationServiceInterface;
use CMS\Modules\Notification\Mail\NativeMailTransport;
use CMS\Modules\Notification\Repository\NotificationRepositoryInterface;
use CMS\Modules\Notification\Repository\PdoNotificationRepository;
use CMS\Modules\Notification\Service\NotificationService;
use CMS\Modules\Site\Contracts\SettingsServiceInterface;
use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\ConfigInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;

/**
 * Wires notifications.
 *
 * `boot()` subscribes to the five content events. That must happen after the modules that
 * emit them are registered, which the provider order in `app/bootstrap.php` guarantees.
 */
final class NotificationServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(
            NotificationRepositoryInterface::class,
            static fn (Container $c): NotificationRepositoryInterface => new PdoNotificationRepository(
                $c->get(Connection::class)
            )
        );

        $container->singleton(
            MailTransportInterface::class,
            static function (Container $c): MailTransportInterface {
                /** @var ConfigInterface $config */
                $config = $c->get(ConfigInterface::class);

                return new NativeMailTransport(
                    (string) $config->get('mail.from_address', 'no-reply@localhost'),
                    (string) $config->get('mail.from_name', ''),
                );
            }
        );

        $container->singleton(
            NotificationServiceInterface::class,
            static fn (Container $c): NotificationServiceInterface => new NotificationService(
                $c->get(NotificationRepositoryInterface::class),
                $c->get(ContentServiceInterface::class),
                $c->get(UserServiceInterface::class),
                $c->get(RoleServiceInterface::class),
                $c->get(SettingsServiceInterface::class),
                $c->get(MailTransportInterface::class),
                $c->get(EventDispatcherInterface::class),
                $c->get(ClockInterface::class),
            )
        );
    }

    public function boot(Container $container): void
    {
        /** @var NotificationServiceInterface $notifications */
        $notifications = $container->get(NotificationServiceInterface::class);

        $notifications->subscribe();
    }
}
