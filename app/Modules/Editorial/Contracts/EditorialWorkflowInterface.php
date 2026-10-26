<?php

declare(strict_types=1);

namespace CMS\Modules\Editorial\Contracts;

use CMS\Modules\Content\Entity\Content;
use CMS\Shared\Contracts\ActorInterface;

/**
 * The editorial state machine.
 *
 * Permission is decided by capability through `ActorInterface::can()`, never by
 * inspecting a role name. That is what lets a site re-point a capability without
 * a code change, and what makes "contributor cannot publish" a data fact rather
 * than a hardcoded exception.
 *
 * FROZEN CONTRACT.
 */
interface EditorialWorkflowInterface
{
    /**
     * Perform a transition, enforcing legality and permission.
     *
     * @param bool $administrative Allow an otherwise-illegal transition. Requires
     *                             the publish capability and is always recorded
     *                             in the log with an explicit note.
     *
     * @throws \CMS\Modules\Editorial\Exceptions\IllegalTransitionException
     * @throws \CMS\Modules\Editorial\Exceptions\WorkflowPermissionDeniedException
     * @throws \CMS\Modules\Editorial\Exceptions\ContentMissingException
     */
    public function transition(
        int $contentId,
        int $siteId,
        ActorInterface $actor,
        string $toStatus,
        ?string $note = null,
        bool $administrative = false,
    ): Content;

    /**
     * Which target statuses are legal from the current one.
     *
     * @return list<string>
     */
    public function allowedTransitions(string $fromStatus): array;

    /**
     * Is this transition legal at all?
     */
    public function canTransition(string $fromStatus, string $toStatus): bool;

    /**
     * Capability required to perform a transition.
     *
     * @throws \CMS\Modules\Editorial\Exceptions\IllegalTransitionException
     */
    public function requiredCapability(string $fromStatus, string $toStatus): string;

    /**
     * Editorial history for a content row, newest first.
     *
     * @return list<\CMS\Modules\Editorial\Entity\WorkflowLog>
     */
    public function history(int $contentId, int $siteId): array;

    /**
     * Schedule a piece of content for publication.
     *
     * Legal from `approved` only (or `scheduled` to reschedule).
     *
     * @throws \CMS\Modules\Editorial\Exceptions\IllegalTransitionException
     * @throws \CMS\Modules\Editorial\Exceptions\WorkflowPermissionDeniedException
     */
    public function schedule(
        int $contentId,
        int $siteId,
        ActorInterface $actor,
        \DateTimeImmutable $publishAt,
    ): Content;
}
