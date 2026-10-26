<?php

declare(strict_types=1);

namespace CMS\Modules\Contribution\Service;

use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Entity\Content;
use CMS\Modules\Content\Exceptions\ContentNotFoundException;
use CMS\Modules\Contribution\Contracts\ContributorServiceInterface;
use CMS\Modules\Contribution\Exceptions\ContentNotEditableException;
use CMS\Modules\Contribution\Exceptions\NotYourContentException;
use CMS\Modules\Editorial\Contracts\EditorialWorkflowInterface;
use CMS\Modules\Editorial\Exceptions\IllegalTransitionException;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;

/**
 * Contributor workflow.
 *
 * Every rule here is an ownership or timing rule, not a publishing rule:
 *
 *  - ownership is checked against `author_id`, and a mismatch is reported as 404 so the
 *    existence of someone else's draft is not confirmed;
 *  - editability is a status test, because editing after submission would mean the
 *    reviewer is reading something different from what was submitted;
 *  - every state change delegates to the editorial workflow, which enforces legality and
 *    capability. This class adds no transitions of its own.
 *
 * That last point is the task pack's "no separate publishing logic", taken literally:
 * exactly one place knows whether a transition is legal.
 */
final class ContributorService implements ContributorServiceInterface
{
    /**
     * Statuses the author may still change.
     *
     * Deliberately narrow. `submitted` and `review` are excluded because the piece is
     * with an editor. `approved`, `scheduled` and `published` are excluded because
     * changing them would alter something an editor already signed off.
     */
    private const EDITABLE_STATUSES = [
        Content::STATUS_DRAFT,
        Content::STATUS_REVISION_REQUESTED,
    ];

    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly ContentServiceInterface $contents,
        private readonly ContentQueryServiceInterface $query,
        private readonly EditorialWorkflowInterface $workflow,
        private readonly ActorProviderInterface $actors,
    ) {
    }

    public function editableStatuses(): array
    {
        return self::EDITABLE_STATUSES;
    }

    public function myDrafts(int $siteId, int $userId, int $limit = 50, int $offset = 0): array
    {
        return $this->listOwn($siteId, $userId, self::EDITABLE_STATUSES, $limit, $offset);
    }

    public function myPublished(int $siteId, int $userId, int $limit = 50, int $offset = 0): array
    {
        return $this->listOwn($siteId, $userId, [Content::STATUS_PUBLISHED], $limit, $offset);
    }

    public function createDraft(int $siteId, int $userId, array $attributes): Content
    {
        // Ownership and initial status are set here, never accepted from the caller: a
        // contributor must not file a piece under someone else's name, nor create one
        // already published.
        $attributes['author_id'] = $userId;
        unset($attributes['status'], $attributes['published_at'], $attributes['scheduled_at']);

        return $this->contents->create($siteId, $attributes);
    }

    public function updateOwn(int $siteId, int $userId, int $contentId, array $attributes): Content
    {
        $content = $this->requireOwn($siteId, $userId, $contentId);

        if (!in_array($content->status, self::EDITABLE_STATUSES, true)) {
            throw new ContentNotEditableException();
        }

        // A contributor cannot move the workflow state or reassign ownership through an
        // edit; both go through the workflow.
        unset(
            $attributes['status'],
            $attributes['author_id'],
            $attributes['published_at'],
            $attributes['scheduled_at'],
        );

        return $this->contents->update($contentId, $siteId, $attributes);
    }

    public function submit(int $siteId, int $userId, int $contentId, ?string $note = null): Content
    {
        $content = $this->requireOwn($siteId, $userId, $contentId);

        // From `draft` and from `revision_requested` the target is the same, and the
        // workflow decides whether that edge exists. Asking it — rather than assuming —
        // keeps this class correct if the state machine changes.
        if (!$this->workflow->canTransition($content->status, Content::STATUS_SUBMITTED)) {
            throw new IllegalTransitionException(
                'This content cannot be submitted from its current status: ' . $content->status . '.'
            );
        }

        // The contributor submits as themselves, so the capability checked inside the
        // workflow is theirs (`workflow.submit`), not an editor's.
        return $this->workflow->transition(
            $contentId,
            $siteId,
            $this->actors->actorForUser($userId, $siteId),
            Content::STATUS_SUBMITTED,
            $note,
        );
    }

    public function history(int $siteId, int $userId, int $contentId): array
    {
        $this->requireOwn($siteId, $userId, $contentId);

        return $this->workflow->history($contentId, $siteId);
    }

    public function isEditable(int $siteId, int $userId, int $contentId): bool
    {
        try {
            $content = $this->contents->get($contentId, $siteId);
        } catch (ContentNotFoundException) {
            return false;
        }

        return $content->authorId === $userId
            && in_array($content->status, self::EDITABLE_STATUSES, true);
    }

    /**
     * List this author's content in the given statuses.
     *
     * The author is a query criterion, not a post-filter, so no other author's rows are
     * loaded at all.
     *
     * @param list<string> $statuses
     *n     * @return list<\CMS\Modules\Content\Entity\ContentSummary>n     */
    private function listOwn(int $siteId, int $userId, array $statuses, int $limit, int $offset): array
    {
        $result = $this->query->paginate($siteId, [
            'author_id' => $userId,
            'statuses' => $statuses,
            'exclude_trash' => true,
            'limit' => max(1, min(self::MAX_LIMIT, $limit)),
            'offset' => max(0, $offset),
            'order' => 'updated',
        ]);

        return $result['items'];
    }

    /**
     * Fetch content and prove the caller owns it.
     *
     * A nonexistent row and another author's row produce the same 404.
     */
    private function requireOwn(int $siteId, int $userId, int $contentId): Content
    {
        try {
            $content = $this->contents->get($contentId, $siteId);
        } catch (ContentNotFoundException) {
            throw new NotYourContentException();
        }

        if ($content->authorId !== $userId) {
            throw new NotYourContentException();
        }

        return $content;
    }
}
