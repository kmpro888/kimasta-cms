<?php

declare(strict_types=1);

namespace CMS\Modules\Editorial;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Core\Database\Connection;
use CMS\Core\Database\ConnectionFactory;
use CMS\Core\Filesystem\LockInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Editorial\Contracts\EditorialWorkflowInterface;
use CMS\Modules\Editorial\Contracts\ScheduledContentFinderInterface;
use CMS\Modules\Editorial\Contracts\SchedulerServiceInterface;
use CMS\Modules\Editorial\Contracts\WorkflowLogRepositoryInterface;
use CMS\Modules\Editorial\Repository\PdoWorkflowLogRepository;
use CMS\Modules\Editorial\Service\EditorialWorkflow;
use CMS\Modules\Editorial\Service\SchedulerService;
use CMS\Modules\Editorial\Support\NullScheduledContentFinder;
use CMS\Modules\Editorial\Workflow\WorkflowStateMachine;
use CMS\Modules\Revision\Contracts\RevisionServiceInterface;
use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;

/**
 * Wires the Editorial module into the container.
 *
 * Depends on Content and Revision through their published contracts, and on
 * `ScheduledContentFinderInterface` for the question "what is due?" so it never
 * queries the `contents` table.
 */
final class EditorialServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        if (!$container->has(Connection::class)) {
            $container->singleton(
                Connection::class,
                static fn (Container $c): Connection => $c->get(ConnectionFactory::class)->create()
            );
        }

        if (!$container->has(ScheduledContentFinderInterface::class)) {
            $container->singleton(
                ScheduledContentFinderInterface::class,
                static fn (): ScheduledContentFinderInterface => new NullScheduledContentFinder()
            );
        }

        $container->singleton(
            WorkflowStateMachine::class,
            static fn (): WorkflowStateMachine => new WorkflowStateMachine()
        );

        $container->singleton(
            WorkflowLogRepositoryInterface::class,
            static fn (Container $c): WorkflowLogRepositoryInterface => new PdoWorkflowLogRepository(
                $c->get(Connection::class)
            )
        );

        $container->singleton(EditorialWorkflow::class, static function (Container $c): EditorialWorkflow {
            return new EditorialWorkflow(
                $c->get(WorkflowStateMachine::class),
                $c->get(ContentServiceInterface::class),
                $c->get(RevisionServiceInterface::class),
                $c->get(WorkflowLogRepositoryInterface::class),
                $c->get(EventDispatcherInterface::class),
                $c->get(Connection::class),
                $c->get(ClockInterface::class),
            );
        });

        $container->singleton(
            EditorialWorkflowInterface::class,
            static fn (Container $c): EditorialWorkflowInterface => $c->get(EditorialWorkflow::class)
        );

        $container->singleton(SchedulerServiceInterface::class, static function (Container $c): SchedulerServiceInterface {
            return new SchedulerService(
                $c->get(EditorialWorkflow::class),
                $c->get(ScheduledContentFinderInterface::class),
                $c->get(LockInterface::class),
                $c->get(ClockInterface::class),
            );
        });
    }

    public function boot(Container $container): void
    {
        // No routes: publishing endpoints belong to a later agent. The cron entry
        // point resolves SchedulerServiceInterface from here.
    }
}
