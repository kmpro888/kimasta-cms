<?php

declare(strict_types=1);

namespace CMS\Modules\Contribution\Contracts;

use CMS\Modules\Content\Entity\Content;

/**
 * The contributor-facing surface: what an author is allowed to do with their own work.
 *
 * This layer exists so the rules live in one place instead of being re-implemented by
 * every controller and every future UI:
 *
 *  - a contributor only ever sees and edits **their own** content;
 *  - they may edit only while the piece is still theirs to change (`draft`,
 *    `revision_requested`) — once it is submitted or in review, editing stops;
 *  - they never publish. Publishing goes through the editorial workflow and requires a
 *    capability they do not hold.
 *
 * It performs **no** publishing logic of its own: every state change delegates to
 * `EditorialWorkflowInterface`, which enforces legality and permission.
 *
 * FROZEN CONTRACT.
 */
interface ContributorServiceInterface
{
    /**
     * Statuses in which the author may still change their own content.
     *
     * @return list<string>
     */
    public function editableStatuses(): array;

    /**
     * The author's own work in a non-published state, newest first.
     *
     * Returns summaries, not full entities: a listing must not load every block payload
     * for every row.
     *
     * @return list<\CMS\Modules\Content\Entity\ContentSummary>
     */
    public function myDrafts(int $siteId, int $userId, int $limit = 50, int $offset = 0): array;

    /**
     * The author's own published work, newest first.
     *
     * @return list<\CMS\Modules\Content\Entity\ContentSummary>
     */
    public function myPublished(int $siteId, int $userId, int $limit = 50, int $offset = 0): array;

    /**
     * Create a draft owned by this author.
     *
     * `author_id` and the initial status are set by the service, never by the caller.
     *
     * @param array<string, mixed> $attributes
     */
    public function createDraft(int $siteId, int $userId, array $attributes): Content;

    /**
     * Update the author's own draft.
     *
     * @param array<string, mixed> $attributes
     *
     * @throws \CMS\Modules\Contribution\Exceptions\NotYourContentException
     * @throws \CMS\Modules\Contribution\Exceptions\ContentNotEditableException
     */
    public function updateOwn(int $siteId, int $userId, int $contentId, array $attributes): Content;

    /**
     * Submit for review, or resubmit after a revision request.
     *
     * @throws \CMS\Modules\Contribution\Exceptions\NotYourContentException
     * @throws \CMS\Modules\Editorial\Exceptions\IllegalTransitionException
     */
    public function submit(int $siteId, int $userId, int $contentId, ?string $note = null): Content;

    /**
     * The editorial history of the author's own piece.
     *
     * @return list<\CMS\Modules\Editorial\Entity\WorkflowLog>
     *
     * @throws \CMS\Modules\Contribution\Exceptions\NotYourContentException
     */
    public function history(int $siteId, int $userId, int $contentId): array;

    /**
     * Whether this author may still edit this piece.
     */
    public function isEditable(int $siteId, int $userId, int $contentId): bool;
}
