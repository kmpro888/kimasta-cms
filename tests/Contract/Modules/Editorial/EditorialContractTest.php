<?php

declare(strict_types=1);

namespace Tests\Contract\Modules\Editorial;

use CMS\Modules\Editorial\Contracts\EditorialWorkflowInterface;
use CMS\Modules\Editorial\Contracts\ScheduledContentFinderInterface;
use CMS\Modules\Editorial\Contracts\SchedulerServiceInterface;
use CMS\Modules\Editorial\Contracts\WorkflowLogRepositoryInterface;
use CMS\Modules\Editorial\Repository\PdoWorkflowLogRepository;
use CMS\Modules\Editorial\Service\EditorialWorkflow;
use CMS\Modules\Editorial\Service\SchedulerService;
use CMS\Modules\Editorial\Workflow\WorkflowStateMachine;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Support\TestCase;

/**
 * Frozen Editorial contract conformance.
 */
final class EditorialContractTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: class-string}>
     */
    public static function contractPairs(): array
    {
        return [
            'workflow' => [EditorialWorkflowInterface::class, EditorialWorkflow::class],
            'scheduler' => [SchedulerServiceInterface::class, SchedulerService::class],
            'workflow log' => [WorkflowLogRepositoryInterface::class, PdoWorkflowLogRepository::class],
        ];
    }

    #[Test]
    public function implementationsMatchTheirContractSurface(): void
    {
        foreach (self::contractPairs() as $label => [$contract, $implementation]) {
            $contractReflection = new ReflectionClass($contract);
            $implementationReflection = new ReflectionClass($implementation);

            self::assertTrue(
                $implementationReflection->implementsInterface($contract),
                $label . ': ' . $implementation . ' does not implement ' . $contract
            );

            foreach ($contractReflection->getMethods() as $method) {
                self::assertTrue(
                    $implementationReflection->hasMethod($method->getName()),
                    $label . ': missing method ' . $method->getName()
                );

                $actual = $implementationReflection->getMethod($method->getName());

                self::assertSame(
                    (string) $method->getReturnType(),
                    (string) $actual->getReturnType(),
                    $label . ': return type drift on ' . $method->getName()
                );

                self::assertSame(
                    count($method->getParameters()),
                    count($actual->getParameters()),
                    $label . ': arity drift on ' . $method->getName()
                );
            }
        }
    }

    #[Test]
    public function theWorkflowDecidesPermissionByCapabilityNotRole(): void
    {
        // The constructor must take an Actor-compatible flow and never a role
        // repository or a role name. This is the "never inspect role name" rule
        // expressed structurally.
        $constructor = (new ReflectionClass(EditorialWorkflow::class))->getConstructor();

        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = (string) $parameter->getType();

            self::assertStringNotContainsStringIgnoringCase('RoleRepository', $type);
            self::assertStringNotContainsStringIgnoringCase('RoleService', $type);
        }

        // And the transition signature takes an Actor, not a user id.
        $transition = (new ReflectionClass(EditorialWorkflowInterface::class))->getMethod('transition');
        $types = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $transition->getParameters()
        );

        self::assertContains(\CMS\Shared\Contracts\ActorInterface::class, $types);
    }

    #[Test]
    public function theSchedulerDeclaresItsOwnLockAndBatchSize(): void
    {
        self::assertSame('editorial-scheduler', SchedulerServiceInterface::LOCK_NAME);
        self::assertSame(50, SchedulerServiceInterface::DEFAULT_BATCH);
    }

    #[Test]
    public function theFinderReturnsContentIdToSiteIdPairs(): void
    {
        // Returning bare ids would let a cross-tenant run guess the tenant; the
        // contract must carry both.
        $method = (new ReflectionClass(ScheduledContentFinderInterface::class))->getMethod('due');
        $doc = (string) $method->getDocComment();

        self::assertStringContainsString('content id => site id', $doc);
    }

    #[Test]
    public function theStateMachineIsSeparateFromTheService(): void
    {
        // Keeping the graph in its own object means it can be unit tested and
        // reviewed without a database.
        self::assertTrue(class_exists(WorkflowStateMachine::class));

        $reflection = new ReflectionClass(WorkflowStateMachine::class);
        $constructor = $reflection->getConstructor();

        self::assertNull($constructor, 'The state machine must be dependency free.');
    }
}
