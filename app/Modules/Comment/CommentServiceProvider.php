<?php

declare(strict_types=1);

namespace CMS\Modules\Comment;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Core\Database\Connection;
use CMS\Modules\Comment\Contracts\CommentServiceInterface;
use CMS\Modules\Comment\Repository\CommentRepositoryInterface;
use CMS\Modules\Comment\Repository\PdoCommentRepository;
use CMS\Modules\Comment\Service\CommentService;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;

/**
 * Wires comment threads.
 *
 * Owns the `comments` table. Reads content and users only through their published
 * contracts.
 */
final class CommentServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(
            CommentRepositoryInterface::class,
            static fn (Container $c): CommentRepositoryInterface => new PdoCommentRepository($c->get(Connection::class))
        );

        $container->singleton(
            CommentServiceInterface::class,
            static fn (Container $c): CommentServiceInterface => new CommentService(
                $c->get(CommentRepositoryInterface::class),
                $c->get(ContentServiceInterface::class),
                $c->get(UserServiceInterface::class),
                $c->get(EventDispatcherInterface::class),
                $c->get(ClockInterface::class),
            )
        );
    }

    public function boot(Container $container): void
    {
        // No routes here: the API controller is registered by the Api provider and the
        // public thread is rendered by the Theme layer.
    }
}
