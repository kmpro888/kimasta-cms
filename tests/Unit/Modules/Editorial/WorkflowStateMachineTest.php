<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Editorial;

use CMS\Modules\Content\Entity\Content;
use CMS\Modules\Editorial\Exceptions\IllegalTransitionException;
use CMS\Modules\Editorial\Workflow\WorkflowStateMachine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * The editorial state machine.
 *
 * Declared as data, so these tests pin the graph itself — including the edges
 * that must NOT exist.
 */
#[CoversClass(WorkflowStateMachine::class)]
final class WorkflowStateMachineTest extends TestCase
{
    private function machine(): WorkflowStateMachine
    {
        return new WorkflowStateMachine();
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function legalEdges(): array
    {
        return [
            'draft to submitted' => ['draft', 'submitted', 'workflow.submit'],
            'submitted to review' => ['submitted', 'review', 'workflow.review'],
            'review to revision_requested' => ['review', 'revision_requested', 'workflow.review'],
            'revision_requested to submitted' => ['revision_requested', 'submitted', 'workflow.submit'],
            'review to approved' => ['review', 'approved', 'workflow.review'],
            'approved to scheduled' => ['approved', 'scheduled', 'content.publish'],
            'approved to published' => ['approved', 'published', 'content.publish'],
            'scheduled to published' => ['scheduled', 'published', 'content.publish'],
            'published to archived' => ['published', 'archived', 'content.publish'],
        ];
    }

    #[Test]
    #[DataProvider('legalEdges')]
    public function legalTransitionsDeclareTheirCapability(string $from, string $to, string $capability): void
    {
        $machine = $this->machine();

        self::assertTrue($machine->canTransition($from, $to));
        self::assertSame($capability, $machine->requiredCapability($from, $to));
        self::assertContains($to, $machine->allowedTransitions($from));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function illegalEdges(): array
    {
        return [
            'draft straight to published' => ['draft', 'published'],
            'draft straight to approved' => ['draft', 'approved'],
            'submitted to published' => ['submitted', 'published'],
            'review to published' => ['review', 'published'],
            'approved back to draft' => ['approved', 'draft'],
            'published back to draft' => ['published', 'draft'],
            'archived to published' => ['archived', 'published'],
            'trash to draft' => ['trash', 'draft'],
            'self transition' => ['published', 'published'],
            'unknown status' => ['published', 'wizard'],
        ];
    }

    #[Test]
    #[DataProvider('illegalEdges')]
    public function illegalTransitionsAreRejected(string $from, string $to): void
    {
        self::assertFalse($this->machine()->canTransition($from, $to));
    }

    #[Test]
    public function requiredCapabilityThrowsForAnIllegalEdge(): void
    {
        $this->expectException(IllegalTransitionException::class);

        $this->machine()->requiredCapability('draft', 'published');
    }

    #[Test]
    public function illegalTransitionCarriesTheFrozenErrorCode(): void
    {
        try {
            $this->machine()->requiredCapability('draft', 'published');
            self::fail('Expected IllegalTransitionException.');
        } catch (IllegalTransitionException $exception) {
            self::assertSame('INVALID_STATE_TRANSITION', $exception->errorCode());
            self::assertSame(409, $exception->status());
            self::assertSame(['from' => 'draft', 'to' => 'published'], $exception->details());
        }
    }

    #[Test]
    public function archivedAndTrashAreTerminal(): void
    {
        $machine = $this->machine();

        self::assertSame([], $machine->allowedTransitions(Content::STATUS_ARCHIVED));
        self::assertSame([], $machine->allowedTransitions(Content::STATUS_TRASH));
    }

    #[Test]
    public function everyContentStatusIsClassified(): void
    {
        $machine = $this->machine();

        // Draft is the entry point; every other status must be reachable by some
        // edge, otherwise content could be created into a dead end.
        foreach (Content::statuses() as $status) {
            if ($status === Content::STATUS_TRASH) {
                continue;
            }

            self::assertTrue(
                $machine->isReachable($status),
                'Status "' . $status . '" is not reachable by any transition.'
            );
        }
    }

    #[Test]
    public function unknownFromStatusHasNoTransitions(): void
    {
        self::assertSame([], $this->machine()->allowedTransitions('wizard'));
    }

    #[Test]
    public function theOverrideCapabilityIsThePublishCapability(): void
    {
        self::assertSame('content.publish', WorkflowStateMachine::OVERRIDE_CAPABILITY);
    }
}
