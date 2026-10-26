<?php

declare(strict_types=1);

namespace CMS\Modules\Editorial\Workflow;

use CMS\Modules\Content\Entity\Content;
use CMS\Modules\Editorial\Exceptions\IllegalTransitionException;

/**
 * The editorial state machine, as data.
 *
 * Transitions and the capability each one requires are declared in a single map
 * rather than scattered through `if` statements. That makes "who may do what"
 * reviewable in one place, and it is why no code ever asks "is this an editor?".
 *
 * The graph (from the task pack):
 *
 *   draft ──submit──▶ submitted ──start review──▶ review
 *   review ──request revision──▶ revision_requested ──resubmit──▶ submitted
 *   review ──approve──▶ approved
 *   approved ──schedule──▶ scheduled ──(cron)──▶ published
 *   approved ──publish──▶ published
 *   published ──archive──▶ archived
 */
final class WorkflowStateMachine
{
    /**
     * from => [to => required capability]
     *
     * @var array<string, array<string, string>>
     */
    private const TRANSITIONS = [
        Content::STATUS_DRAFT => [
            Content::STATUS_SUBMITTED => 'workflow.submit',
        ],
        Content::STATUS_SUBMITTED => [
            Content::STATUS_REVIEW => 'workflow.review',
        ],
        Content::STATUS_REVIEW => [
            Content::STATUS_REVISION_REQUESTED => 'workflow.review',
            Content::STATUS_APPROVED => 'workflow.review',
        ],
        Content::STATUS_REVISION_REQUESTED => [
            Content::STATUS_SUBMITTED => 'workflow.submit',
        ],
        Content::STATUS_APPROVED => [
            Content::STATUS_SCHEDULED => 'content.publish',
            Content::STATUS_PUBLISHED => 'content.publish',
        ],
        Content::STATUS_SCHEDULED => [
            Content::STATUS_PUBLISHED => 'content.publish',
        ],
        Content::STATUS_PUBLISHED => [
            Content::STATUS_ARCHIVED => 'content.publish',
        ],
        // Terminal or non-editorial states have no outgoing editorial edges.
        Content::STATUS_ARCHIVED => [],
        Content::STATUS_TRASH => [],
    ];

    /**
     * Capability required for an administrative override.
     */
    public const OVERRIDE_CAPABILITY = 'content.publish';

    /** @return list<string> */
    public function allowedTransitions(string $fromStatus): array
    {
        return array_keys(self::TRANSITIONS[$fromStatus] ?? []);
    }

    public function canTransition(string $fromStatus, string $toStatus): bool
    {
        return isset(self::TRANSITIONS[$fromStatus][$toStatus]);
    }

    /**
     * @throws IllegalTransitionException
     */
    public function requiredCapability(string $fromStatus, string $toStatus): string
    {
        $capability = self::TRANSITIONS[$fromStatus][$toStatus] ?? null;

        if ($capability === null) {
            throw new IllegalTransitionException($fromStatus, $toStatus);
        }

        return $capability;
    }

    /**
     * Whether a status can be reached by any editorial edge at all.
     */
    public function isReachable(string $status): bool
    {
        if ($status === Content::STATUS_DRAFT) {
            return true;
        }

        foreach (self::TRANSITIONS as $targets) {
            if (isset($targets[$status])) {
                return true;
            }
        }

        return false;
    }
}
