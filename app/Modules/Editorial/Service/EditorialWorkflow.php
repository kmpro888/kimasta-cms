<?php

declare(strict_types=1);

namespace CMS\Modules\Editorial\Service;

use CMS\Core\Database\Connection;
use CMS\Core\Events\DomainEvent;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Entity\Content;
use CMS\Modules\Content\Exceptions\ContentNotFoundException;
use CMS\Modules\Editorial\Contracts\EditorialWorkflowInterface;
use CMS\Modules\Editorial\Contracts\WorkflowLogRepositoryInterface;
use CMS\Modules\Editorial\Exceptions\ContentMissingException;
use CMS\Modules\Editorial\Exceptions\IllegalTransitionException;
use CMS\Modules\Editorial\Exceptions\WorkflowPermissionDeniedException;
use CMS\Modules\Editorial\Workflow\WorkflowStateMachine;
use CMS\Modules\Revision\Contracts\RevisionServiceInterface;
use CMS\Shared\Contracts\ActorInterface;
use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;
use DateTimeImmutable;
use Throwable;

/**
 * Editorial state machine implementation.
 *
 * Two rules shape this class:
 *
 * 1. **Permission is a capability question, never a role question.** Every check
 *    goes through `ActorInterface::can()`. A site can therefore re-point a
 *    capability without touching this file.
 *
 * 2. **A publish is one transaction.** Status, revision snapshot, URL registry,
 *    timestamps and the audit row either all land or none do. The workflow event
 *    is dispatched only after commit, so a listener never observes a publish that
 *    was subsequently rolled back.
 */
final class EditorialWorkflow implements EditorialWorkflowInterface
{
    /**
     * Status => emitted event name.
     *
     * @var array<string, string>
     */
    private const EVENTS = [
        Content::STATUS_SUBMITTED => 'content.submitted',
        Content::STATUS_REVIEW => 'content.review_started',
        Content::STATUS_REVISION_REQUESTED => 'content.revision_requested',
        Content::STATUS_APPROVED => 'content.approved',
        Content::STATUS_SCHEDULED => 'content.scheduled',
        Content::STATUS_PUBLISHED => 'content.published',
        Content::STATUS_ARCHIVED => 'content.archived',
    ];

    public function __construct(
        private readonly WorkflowStateMachine $machine,
        private readonly ContentServiceInterface $contents,
        private readonly RevisionServiceInterface $revisions,
        private readonly WorkflowLogRepositoryInterface $logs,
        private readonly EventDispatcherInterface $events,
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    public function transition(
        int $contentId,
        int $siteId,
        ActorInterface $actor,
        string $toStatus,
        ?string $note = null,
        bool $administrative = false,
    ): Content {
        $content = $this->loadContent($contentId, $siteId);
        $fromStatus = $content->status;

        if ($administrative) {
            // An override still requires the publish capability; it only skips
            // the graph. Both facts are recorded so the log explains itself.
            if (!$actor->can(WorkflowStateMachine::OVERRIDE_CAPABILITY)) {
                throw new WorkflowPermissionDeniedException(WorkflowStateMachine::OVERRIDE_CAPABILITY);
            }

            $note = trim(($note ?? '') . ' [administrative override]');
        } else {
            if (!$this->machine->canTransition($fromStatus, $toStatus)) {
                throw new IllegalTransitionException($fromStatus, $toStatus);
            }

            $capability = $this->machine->requiredCapability($fromStatus, $toStatus);

            if (!$actor->can($capability)) {
                throw new WorkflowPermissionDeniedException($capability);
            }
        }

        return $this->apply($content, $siteId, $actor->id() ?? 0, $toStatus, $note === '' ? null : $note);
    }

    public function allowedTransitions(string $fromStatus): array
    {
        return $this->machine->allowedTransitions($fromStatus);
    }

    public function canTransition(string $fromStatus, string $toStatus): bool
    {
        return $this->machine->canTransition($fromStatus, $toStatus);
    }

    public function requiredCapability(string $fromStatus, string $toStatus): string
    {
        return $this->machine->requiredCapability($fromStatus, $toStatus);
    }

    public function history(int $contentId, int $siteId): array
    {
        return $this->logs->historyFor($contentId, $siteId);
    }

    public function schedule(
        int $contentId,
        int $siteId,
        ActorInterface $actor,
        DateTimeImmutable $publishAt,
    ): Content {
        $content = $this->loadContent($contentId, $siteId);

        $fromStatus = $content->status;

        // Scheduling is legal from `approved`; an already-scheduled item may be
        // rescheduled, which is a timestamp change rather than a transition.
        if ($fromStatus !== Content::STATUS_SCHEDULED) {
            if (!$this->machine->canTransition($fromStatus, Content::STATUS_SCHEDULED)) {
                throw new IllegalTransitionException($fromStatus, Content::STATUS_SCHEDULED);
            }

            $capability = $this->machine->requiredCapability($fromStatus, Content::STATUS_SCHEDULED);

            if (!$actor->can($capability)) {
                throw new WorkflowPermissionDeniedException($capability);
            }
        } elseif (!$actor->can('content.publish')) {
            throw new WorkflowPermissionDeniedException('content.publish');
        }

        return $this->apply(
            $content,
            $siteId,
            $actor->id() ?? 0,
            Content::STATUS_SCHEDULED,
            'Scheduled for ' . $publishAt->format('Y-m-d H:i:s'),
            $publishAt,
        );
    }

    /**
     * Run a transition as the system (cron), bypassing the capability check.
     *
     * Only the scheduler calls this, and it is recorded with user id 0 so the
     * audit trail shows an automated change rather than a silent one.
     */
    public function transitionAsSystem(int $contentId, int $siteId, string $toStatus, ?string $note = null): Content
    {
        $content = $this->loadContent($contentId, $siteId);

        return $this->apply($content, $siteId, 0, $toStatus, $note);
    }

    /**
     * The transaction boundary for every editorial change.
     */
    private function apply(
        Content $content,
        int $siteId,
        int $userId,
        string $toStatus,
        ?string $note = null,
        ?DateTimeImmutable $scheduledAt = null,
    ): Content {
        $contentId = (int) $content->id;
        $fromStatus = $content->status;

        // If a caller already opened a transaction, join it rather than starting
        // a nested one that would commit out from under them.
        $ownsTransaction = !$this->db->inTransaction();

        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            // Snapshot before the change: the revision must describe what was
            // replaced, not what replaced it.
            $this->revisions->snapshot($contentId, $siteId, $userId);

            $attributes = ['status' => $toStatus];

            if ($scheduledAt !== null) {
                $attributes['scheduled_at'] = $scheduledAt;
            }

            $updated = $this->contents->update($contentId, $siteId, $attributes);

            // One audit row per transition, inside the same transaction as the
            // change it describes.
            $this->logs->record($siteId, $contentId, $userId, $fromStatus, $toStatus, $note);

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        // Only after commit: a listener must never see a publish that was rolled
        // back.
        $eventName = self::EVENTS[$toStatus] ?? null;

        if ($eventName !== null) {
            $this->events->dispatch(DomainEvent::create(
                $eventName,
                [
                    'id' => $contentId,
                    'site_id' => $siteId,
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'user_id' => $userId,
                    'note' => $note,
                ],
                $siteId,
                $this->clock,
            ));
        }

        return $updated;
    }

    private function loadContent(int $contentId, int $siteId): Content
    {
        try {
            return $this->contents->get($contentId, $siteId);
        } catch (ContentNotFoundException) {
            throw new ContentMissingException();
        }
    }
}
