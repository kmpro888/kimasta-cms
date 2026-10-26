<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Editorial;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Repository\PdoContentRepository;
use CMS\Modules\Content\Repository\PdoUrlRegistryRepository;
use CMS\Modules\Content\Service\ContentService;
use CMS\Modules\Content\Service\ContentTypeRegistry;
use CMS\Modules\Content\Service\PlainBlockCompiler;
use CMS\Modules\Content\Service\SlugService;
use CMS\Modules\Content\Service\UrlGenerator;
use CMS\Modules\Editorial\Contracts\EditorialWorkflowInterface;
use CMS\Modules\Editorial\Exceptions\ContentMissingException;
use CMS\Modules\Editorial\Exceptions\IllegalTransitionException;
use CMS\Modules\Editorial\Exceptions\WorkflowPermissionDeniedException;
use CMS\Modules\Editorial\Repository\PdoWorkflowLogRepository;
use CMS\Modules\Editorial\Service\EditorialWorkflow;
use CMS\Modules\Editorial\Workflow\WorkflowStateMachine;
use CMS\Modules\Identity\Auth\Actor;
use CMS\Modules\Revision\Repository\PdoRevisionRepository;
use CMS\Modules\Revision\Service\RevisionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\Support\ModuleDatabaseTestCase;
use Tests\Support\RecordingEventDispatcher;

/**
 * Editorial workflow against a real database.
 *
 * Permission tests use real Actor instances with real capability sets, so
 * "contributor cannot publish" is asserted through the same path production uses.
 */
#[CoversClass(EditorialWorkflow::class)]
final class EditorialWorkflowTest extends ModuleDatabaseTestCase
{
    private EditorialWorkflowInterface $workflow;

    private ContentServiceInterface $contents;

    private PdoWorkflowLogRepository $logs;

    private RecordingEventDispatcher $events;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteId = $this->createSiteRow('main', 'example.com');

        $repository = new PdoContentRepository($this->db);
        $clock = new FrozenClock();
        $this->events = new RecordingEventDispatcher();
        $this->logs = new PdoWorkflowLogRepository($this->db);

        $this->contents = new ContentService(
            $repository,
            new SlugService($repository),
            new UrlGenerator(new PdoUrlRegistryRepository($this->db)),
            new PlainBlockCompiler(),
            new ContentTypeRegistry(),
            new RecordingEventDispatcher(),
            $clock,
        );

        $revisions = new RevisionService(
            new PdoRevisionRepository($this->db),
            $this->contents,
            $clock,
        );

        $this->workflow = new EditorialWorkflow(
            new WorkflowStateMachine(),
            $this->contents,
            $revisions,
            $this->logs,
            $this->events,
            $this->db,
            $clock,
        );
    }

    /** A contributor: may create and submit, may not publish. */
    private function contributor(int $userId = 10): Actor
    {
        return new Actor($userId, $this->siteId, ['content.create', 'content.read', 'workflow.submit']);
    }

    /** A reviewer: may review and request revision, may not publish. */
    private function reviewer(int $userId = 20): Actor
    {
        return new Actor($userId, $this->siteId, ['content.read', 'workflow.review']);
    }

    /** An editor: may publish. */
    private function editor(int $userId = 30): Actor
    {
        return new Actor($userId, $this->siteId, ['content.read', 'content.publish', 'workflow.review']);
    }

    private function makeDraft(string $title = 'A Draft'): int
    {
        return (int) $this->contents->create($this->siteId, [
            'title' => $title,
            'author_id' => 10,
            'content_json' => [['type' => 'paragraph', 'data' => ['text' => 'Body']]],
        ])->id;
    }

    #[Test]
    public function aContributorCanSubmit(): void
    {
        $contentId = $this->makeDraft();

        $content = $this->workflow->transition($contentId, $this->siteId, $this->contributor(), 'submitted');

        self::assertSame('submitted', $content->status);
    }

    #[Test]
    public function aContributorCannotPublish(): void
    {
        $contentId = $this->makeDraft();

        // Walk the legal path to approved first, then try to publish as a
        // contributor who somehow reached that state.
        $this->workflow->transition($contentId, $this->siteId, $this->contributor(), 'submitted');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'review');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'approved');

        try {
            $this->workflow->transition($contentId, $this->siteId, $this->contributor(), 'published');
            self::fail('A contributor was allowed to publish.');
        } catch (WorkflowPermissionDeniedException $exception) {
            self::assertSame('FORBIDDEN', $exception->errorCode());
            self::assertSame(403, $exception->status());
        }

        self::assertSame('approved', $this->contents->get($contentId, $this->siteId)->status);
    }

    #[Test]
    public function aReviewerCanRequestRevision(): void
    {
        $contentId = $this->makeDraft();

        $this->workflow->transition($contentId, $this->siteId, $this->contributor(), 'submitted');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'review');

        $content = $this->workflow->transition(
            $contentId,
            $this->siteId,
            $this->reviewer(),
            'revision_requested',
            'Please add a source.'
        );

        self::assertSame('revision_requested', $content->status);
    }

    #[Test]
    public function aRevisionRequestedItemCanBeResubmitted(): void
    {
        $contentId = $this->makeDraft();

        $this->workflow->transition($contentId, $this->siteId, $this->contributor(), 'submitted');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'review');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'revision_requested');
        $content = $this->workflow->transition($contentId, $this->siteId, $this->contributor(), 'submitted');

        self::assertSame('submitted', $content->status);
    }

    #[Test]
    public function anEditorCanPublish(): void
    {
        $contentId = $this->makeDraft();

        $this->workflow->transition($contentId, $this->siteId, $this->contributor(), 'submitted');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'review');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'approved');

        $content = $this->workflow->transition($contentId, $this->siteId, $this->editor(), 'published');

        self::assertSame('published', $content->status);
        self::assertNotNull($content->publishedAt);
    }

    #[Test]
    public function publishingRegistersThePermalink(): void
    {
        $contentId = $this->makeDraft('Published Path');

        $this->workflow->transition($contentId, $this->siteId, $this->contributor(), 'submitted');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'review');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'approved');
        $this->workflow->transition($contentId, $this->siteId, $this->editor(), 'published');

        self::assertNotNull(
            (new PdoUrlRegistryRepository($this->db))->resolve($this->siteId, '/blog/published-path')
        );
    }

    #[Test]
    public function anIllegalTransitionIsRejected(): void
    {
        $contentId = $this->makeDraft();

        $this->expectException(IllegalTransitionException::class);

        // draft -> published is not an edge.
        $this->workflow->transition($contentId, $this->siteId, $this->editor(), 'published');
    }

    #[Test]
    public function anIllegalTransitionLeavesTheContentUntouched(): void
    {
        $contentId = $this->makeDraft();

        try {
            $this->workflow->transition($contentId, $this->siteId, $this->editor(), 'published');
        } catch (IllegalTransitionException) {
            // expected
        }

        self::assertSame('draft', $this->contents->get($contentId, $this->siteId)->status);
        self::assertSame(0, $this->logs->countFor($contentId, $this->siteId));
    }

    #[Test]
    public function anAdministrativeOverrideCanSkipTheGraph(): void
    {
        $contentId = $this->makeDraft('Forced');

        $content = $this->workflow->transition(
            $contentId,
            $this->siteId,
            $this->editor(),
            'published',
            'Emergency publish',
            true
        );

        self::assertSame('published', $content->status);

        $history = $this->workflow->history($contentId, $this->siteId);

        self::assertCount(1, $history);
        self::assertStringContainsString('administrative override', (string) $history[0]->note);
    }

    #[Test]
    public function aContributorCannotUseTheAdministrativeOverride(): void
    {
        $contentId = $this->makeDraft();

        $this->expectException(WorkflowPermissionDeniedException::class);

        $this->workflow->transition($contentId, $this->siteId, $this->contributor(), 'published', null, true);
    }

    #[Test]
    public function everyTransitionIsLogged(): void
    {
        $contentId = $this->makeDraft();

        $this->workflow->transition($contentId, $this->siteId, $this->contributor(), 'submitted');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'review');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'approved');

        self::assertSame(3, $this->logs->countFor($contentId, $this->siteId));

        $history = $this->workflow->history($contentId, $this->siteId);

        // Newest first.
        self::assertSame('approved', $history[0]->toStatus);
        self::assertSame('review', $history[0]->fromStatus);
        self::assertSame('submitted', $history[2]->toStatus);
        self::assertSame('draft', $history[2]->fromStatus);
    }

    #[Test]
    public function eachTransitionEmitsItsNamedEvent(): void
    {
        $contentId = $this->makeDraft();

        $this->workflow->transition($contentId, $this->siteId, $this->contributor(), 'submitted');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'review');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'revision_requested');
        $this->workflow->transition($contentId, $this->siteId, $this->contributor(), 'submitted');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'review');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'approved');
        $this->workflow->transition($contentId, $this->siteId, $this->editor(), 'published');
        $this->workflow->transition($contentId, $this->siteId, $this->editor(), 'archived');

        self::assertSame([
            'content.submitted',
            'content.review_started',
            'content.revision_requested',
            'content.submitted',
            'content.review_started',
            'content.approved',
            'content.published',
            'content.archived',
        ], $this->events->names());
    }

    #[Test]
    public function aFailedTransitionEmitsNoEvent(): void
    {
        $contentId = $this->makeDraft();

        try {
            $this->workflow->transition($contentId, $this->siteId, $this->editor(), 'published');
        } catch (IllegalTransitionException) {
            // expected
        }

        self::assertSame([], $this->events->names());
    }

    #[Test]
    public function publishingSnapshotsARevision(): void
    {
        $contentId = $this->makeDraft();

        $this->workflow->transition($contentId, $this->siteId, $this->contributor(), 'submitted');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'review');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'approved');
        $this->workflow->transition($contentId, $this->siteId, $this->editor(), 'published');

        $revisionCount = (int) ($this->db->scalar(
            'SELECT COUNT(*) FROM content_revisions WHERE content_id = :id AND site_id = :site',
            ['id' => $contentId, 'site' => $this->siteId]
        ) ?? 0);

        // Four transitions, each snapshotting first, capped at three.
        self::assertSame(3, $revisionCount);
    }

    #[Test]
    public function schedulingStoresThePublishTime(): void
    {
        $contentId = $this->makeDraft();

        $this->workflow->transition($contentId, $this->siteId, $this->contributor(), 'submitted');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'review');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'approved');

        $at = new \DateTimeImmutable('2026-02-01 09:00:00', new \DateTimeZone('UTC'));

        $content = $this->workflow->schedule($contentId, $this->siteId, $this->editor(), $at);

        self::assertSame('scheduled', $content->status);
        self::assertNotNull($content->scheduledAt);
        self::assertSame('2026-02-01', $content->scheduledAt->format('Y-m-d'));
    }

    #[Test]
    public function aContributorCannotSchedule(): void
    {
        $contentId = $this->makeDraft();

        $this->workflow->transition($contentId, $this->siteId, $this->contributor(), 'submitted');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'review');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'approved');

        $this->expectException(WorkflowPermissionDeniedException::class);

        $this->workflow->schedule(
            $contentId,
            $this->siteId,
            $this->contributor(),
            new \DateTimeImmutable('2026-02-01 09:00:00')
        );
    }

    #[Test]
    public function aDraftCannotBeScheduled(): void
    {
        $contentId = $this->makeDraft();

        $this->expectException(IllegalTransitionException::class);

        $this->workflow->schedule(
            $contentId,
            $this->siteId,
            $this->editor(),
            new \DateTimeImmutable('2026-02-01 09:00:00')
        );
    }

    #[Test]
    public function anAlreadyScheduledItemCanBeRescheduled(): void
    {
        $contentId = $this->makeDraft();

        $this->workflow->transition($contentId, $this->siteId, $this->contributor(), 'submitted');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'review');
        $this->workflow->transition($contentId, $this->siteId, $this->reviewer(), 'approved');

        $first = new \DateTimeImmutable('2026-02-01 09:00:00', new \DateTimeZone('UTC'));
        $second = new \DateTimeImmutable('2026-03-01 09:00:00', new \DateTimeZone('UTC'));

        $this->workflow->schedule($contentId, $this->siteId, $this->editor(), $first);
        $content = $this->workflow->schedule($contentId, $this->siteId, $this->editor(), $second);

        self::assertSame('2026-03-01', $content->scheduledAt?->format('Y-m-d'));
    }

    #[Test]
    public function aForeignContentIdIsRejected(): void
    {
        $contentId = $this->makeDraft();
        $otherSite = $this->createSiteRow('other', 'other.example');

        $this->expectException(ContentMissingException::class);

        $this->workflow->transition($contentId, $otherSite, $this->editor(), 'submitted');
    }

    #[Test]
    public function allowedTransitionsAreExposed(): void
    {
        self::assertSame(['submitted'], $this->workflow->allowedTransitions('draft'));
        self::assertTrue($this->workflow->canTransition('review', 'approved'));
        self::assertFalse($this->workflow->canTransition('draft', 'approved'));
    }

    #[Test]
    public function requiredCapabilityIsExposed(): void
    {
        self::assertSame('workflow.submit', $this->workflow->requiredCapability('draft', 'submitted'));
        self::assertSame('content.publish', $this->workflow->requiredCapability('approved', 'published'));
    }
}
