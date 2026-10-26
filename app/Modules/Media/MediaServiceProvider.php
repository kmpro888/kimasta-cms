<?php

declare(strict_types=1);

namespace CMS\Modules\Media;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Core\Database\Connection;
use CMS\Core\Database\ConnectionFactory;
use CMS\Modules\Media\Contracts\ImageProcessorInterface;
use CMS\Modules\Media\Contracts\MediaRepositoryInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Media\Contracts\MediaUsageCheckerInterface;
use CMS\Modules\Media\Image\ImageProcessor;
use CMS\Modules\Media\Repository\PdoMediaRepository;
use CMS\Modules\Media\Service\MediaService;
use CMS\Modules\Media\Support\NullMediaUsageChecker;
use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\ConfigInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;

/**
 * Wires the Media module into the container.
 */
final class MediaServiceProvider implements ServiceProviderInterface
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
            MediaRepositoryInterface::class,
            static fn (Container $c): MediaRepositoryInterface => new PdoMediaRepository($c->get(Connection::class))
        );

        $container->singleton(
            ImageProcessorInterface::class,
            static fn (): ImageProcessorInterface => new ImageProcessor()
        );

        // Only install the permissive default when no owning module has
        // registered a real reference checker.
        if (!$container->has(MediaUsageCheckerInterface::class)) {
            $container->singleton(
                MediaUsageCheckerInterface::class,
                static fn (): MediaUsageCheckerInterface => new NullMediaUsageChecker()
            );
        }

        $container->singleton(MediaServiceInterface::class, static function (Container $c): MediaServiceInterface {
            /** @var ConfigInterface $config */
            $config = $c->get(ConfigInterface::class);

            return new MediaService(
                $c->get(MediaRepositoryInterface::class),
                $c->get(ImageProcessorInterface::class),
                $c->get(MediaUsageCheckerInterface::class),
                $c->get(EventDispatcherInterface::class),
                $c->get(ClockInterface::class),
                self::uploadsDirectory($config),
                (int) $config->get('media.max_upload_size', 10485760),
            );
        });
    }

    public function boot(Container $container): void
    {
        // No routes: the admin upload endpoint belongs to a later agent.
    }

    /**
     * Absolute path to public/uploads, which is the document-root-friendly
     * location the web server serves statically.
     */
    public static function uploadsDirectory(ConfigInterface $config): string
    {
        $base = (string) $config->get('app.base_path', '');

        if ($base === '') {
            $base = dirname(__DIR__, 3);
        }

        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads';
    }
}
