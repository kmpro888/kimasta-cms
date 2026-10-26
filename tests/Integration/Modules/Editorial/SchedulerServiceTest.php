<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Editorial;

use CMS\Core\Filesystem\FileLock;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Repository\PdoContentRepository;
use CMS\Modules\Content\Repository\PdoUrlRegistryRepository;
use CMS\Modules\Content\Service\ContentService;
use CMS\Modules\Content\Service\ContentTypeRegistry;
use CMS\Modules\Content\Service\PlainBlockCompiler;
use CMS\Modules\Content\Service\SlugService;
use CMS\Modules\Content\Service\UrlGenerator;
use CMS\Modules\Content\Support\ContentScheduledFinder;
use CMS\Modules\Editorial\Contracts\SchedulerServiceInterface;
use CMS\Modules\Editorial\Repository\PdoWorkflowLogRepository;
use CMS\Modules\Editorial\Service\EditorialWorkflow;
use CMS\Modules\Editorial\Service\SchedulerService;
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
 * Scheduled publishing.
 *
 * Cron on shared hosting is the target, so the tests focus on the properties that
 * make that safe: bounded batches, a real lock, and no dependency on a human
 * actor.
 */
#[CoversClass(SchedulerService::class)]
final class SchedulerServiceTest extends ModuleDatabaseTestCase
{
    private SchedulerServiceInterface $scheduler;

    private EditorialWorkflow $workflow;

    private ContentServiceInterface $contents;

    private FrozenClock $clock;

    private RecordingEventDispatcher $events;

    private PdoWorkflowLogRepository $logs;

    private int $siteId;

    private Actor $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteId = $this->createSiteRow('main', 'example.com');

        $repository = new PdoContentRepository($this->db);
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-01-15 12:00:00', new \DateTimeZone('UTC')));
        $this->events = new RecordingEventDispatcher();
        $this->logs = new PdoWorkflowLogRepository($this->db);

        $this->contents = new ContentService(
            $repository,
            new SlugService($repository),
            new UrlGenerator(new PdoUrlRegistryRepository($this->db)),
            new PlainBlockCompiler(),
            new ContentTypeRegistry(),
            new RecordingEventDispatcher(),
            $this->clock,
        );

        $revisions = new RevisionService(new PdoRevisionRepository($this->db), $this->contents, $this->clock);

        $this->workflow = new EditorialWorkflow(
            new WorkflowStateMachine(),
            $this->contents,
            $revisions,
            $this->logs,
            $this->events,
            $this->db,
            $this->clock,
        );

        $this->scheduler = new SchedulerService(
            $this->workflow,
            new ContentScheduledFinder($this->db),
            new FileLock($this->path('locks')),
            $this->clock,
        );

        $this->editor = new Actor(30, $this->siteId, ['content.read', 'content.publish', 'workflow.review']);
    }

    /**
     * Drive a fresh content row to `scheduled` at the given time.
     */
    private function scheduleContent(string $title, \DateTimeImmutable $publishAt, ?int $siteId = null): int
    {
        $site = $siteId ?? $this->siteId;

        $contentId = (int) $this->contents->create($site, [
            'title' => $title,
            'author_id' => 10,
            'content_json' => [['type' => 'paragraph', 'data' => ['text' => 'Body']]],
        ])->id;

        $contributor = new Actor(10, $site, ['content.create', 'workflow.submit']);
        $editor = new Actor(30, $site, ['content.publish', 'workflow.review']);

        $this->workflow->transition($contentId, $site, $contributor, 'submitted');
        $this->workflow->transition($contentId, $site, $editor, 'review');
        $this->workflow->transition($contentId, $site, $editor, 'approved');
        $this->workflow->schedule($contentId, $site, $editor, $publishAt);

        return $contentId;
    }

    #[Test]
    public function dueContentIsPublished(): void
    {
        $contentId = $this->scheduleContent('Due Now', new \DateTimeImmutable('2026-01-15 11:00:00'));

        $published = $this->scheduler->publishDue($this->siteId);

        self::assertSame(1, $published);
        self::assertSame('published', $this->contents->get($contentId, $this->siteId)->status);
    }

    #[Test]
    public function futureContentIsNotPublished(): void
    {
        $contentId = $this->scheduleContent('Not Yet', new \DateTimeImmutable('2026-02-01 09:00:00'));

        $published = $this->scheduler->publishDue($this->siteId);

        self::assertSame(0, $published);
        self::assertSame('scheduled', $this->contents->get($contentId, $this->siteId)->status);
    }

    #[Test]
    public function aBatchPublishesEverythingDueUpToTheLimit(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->scheduleContent('Due ' . $i, new \DateTimeImmutable('2026-01-15 10:00:00'));
        }

        self::assertSame(3, $this->scheduler->publishDue($this->siteId));
    }

    #[Test]
    public function theBatchLimitIsRespected(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->scheduleContent('Due ' . $i, new \DateTimeImmutable('2026-01-15 10:00:00'));
        }

        // One run drains at most 2; the backlog survives for the next run.
        self::assertSame(2, $this->scheduler->publishDue($this->siteId, 2));
        self::assertSame(3, $this->scheduler->publishDue($this->siteId, 10));
    }

    #[Test]
    public function dueIdsReportsWithoutPublishing(): void
    {
        $contentId = $this->scheduleContent('Peek', new \DateTimeImmutable('2026-01-15 10:00:00'));

        self::assertSame([$contentId], $this->scheduler->dueIds($this->siteId));
        self::assertSame('scheduled', $this->contents->get($contentId, $this->siteId)->status);
    }

    #[Test]
    public function publishingBySchedulerIsLoggedAsSystem(): void
    {
        $contentId = $this->scheduleContent('System Publish', new \DateTimeImmutable('2026-01-15 10:00:00'));

        $this->scheduler->publishDue($this->siteId);

        $history = $this->workflow->history($contentId, $this->siteId);

        self::assertSame('published', $history[0]->toStatus);
        self::assertSame(0, $history[0]->userId);
        self::assertTrue($history[0]->isSystem());
    }

    #[Test]
    public function publishingBySchedulerEmitsContentPublished(): void
    {
        $this->scheduleContent('Event Publish', new \DateTimeImmutable('2026-01-15 10:00:00'));

        $this->events->clear();
        $this->scheduler->publishDue($this->siteId);

        self::assertSame(['content.published'], $this->events->names());
    }

    #[Test]
    public function aHeldLockMakesTheRunYieldInsteadOfDoublePublishing(): void
    {
        $contentId = $this->scheduleContent('Locked', new \DateTimeImmutable('2026-01-15 10:00:00'));

        $blocker = new FileLock($this->path('locks'));
        self::assertTrue($blocker->acquire(SchedulerServiceInterface::LOCK_NAME));

        try {
            // A second, concurrent run must do nothing rather than publish twice.
            self::assertSame(0, $this->scheduler->publishDue($this->siteId));
            self::assertSame('scheduled', $this->contents->get($contentId, $this->siteId)->status);
        } finally {
            $blocker->release(SchedulerServiceInterface::LOCK_NAME);
        }

        // Once the lock is free the work proceeds.
        self::assertSame(1, $this->scheduler->publishDue($this->siteId));
    }

    #[Test]
    public function theLockIsReleasedAfterARun(): void
    {
        $this->scheduleContent('Release', new \DateTimeImmutable('2026-01-15 10:00:00'));

        $this->scheduler->publishDue($this->siteId);

        // A fresh lock instance must be able to acquire, proving release.
        $probe = new FileLock($this->path('locks'));
        self::assertTrue($probe->acquire(SchedulerServiceInterface::LOCK_NAME));
        $probe->release(SchedulerServiceInterface::LOCK_NAME);
    }

    #[Test]
    public function aCrossSiteRunUsesEachRowsOwnSite(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        $first = $this->scheduleContent('Site One', new \DateTimeImmutable('2026-01-15 10:00:00'));
        $second = $this->scheduleContent('Site Two', new \DateTimeImmutable('2026-01-15 10:00:00'), $otherSite);

        // No site argument: the scheduler must derive the tenant per row.
        $published = $this->scheduler->publishDue(null);

        self::assertSame(2, $published);
        self::assertSame('published', $this->contents->get($first, $this->siteId)->status);
        self::assertSame('published', $this->contents->get($second, $otherSite)->status);
    }

    #[Test]
    public function aSiteScopedRunIgnoresOtherTenants(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        $mine = $this->scheduleContent('Mine', new \DateTimeImmutable('2026-01-15 10:00:00'));
        $theirs = $this->scheduleContent('Theirs', new \DateTimeImmutable('2026-01-15 10:00:00'), $otherSite);

        self::assertSame(1, $this->scheduler->publishDue($this->siteId));

        self::assertSame('published', $this->contents->get($mine, $this->siteId)->status);
        self::assertSame('scheduled', $this->contents->get($theirs, $otherSite)->status);
    }

    #[Test]
    public function aScheduledItemBecomesPubliclyVisibleAfterPublishing(): void
    {
        $contentId = $this->scheduleContent('Goes Live', new \DateTimeImmutable('2026-01-15 10:00:00'));

        $this->scheduler->publishDue($this->siteId);

        $content = $this->contents->get($contentId, $this->siteId);

        self::assertTrue($content->isPubliclyVisible($this->clock->now()));
        self::assertNotNull(
            (new PdoUrlRegistryRepository($this->db))->resolve($this->siteId, '/blog/goes-live')
        );
    }

    #[Test]
    public function anEmptyQueueIsHarmless(): void
    {
        self::assertSame(0, $this->scheduler->publishDue($this->siteId));
        self::assertSame([], $this->scheduler->dueIds($this->siteId));
    }
}
