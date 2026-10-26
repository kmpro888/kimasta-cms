<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Revision;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Repository\PdoContentRepository;
use CMS\Modules\Content\Repository\PdoUrlRegistryRepository;
use CMS\Modules\Content\Service\ContentService;
use CMS\Modules\Content\Service\ContentTypeRegistry;
use CMS\Modules\Content\Service\PlainBlockCompiler;
use CMS\Modules\Content\Service\SlugService;
use CMS\Modules\Content\Service\UrlGenerator;
use CMS\Modules\Revision\Contracts\RevisionServiceInterface;
use CMS\Modules\Revision\Exceptions\ContentMissingException;
use CMS\Modules\Revision\Exceptions\RevisionNotFoundException;
use CMS\Modules\Revision\Repository\PdoRevisionRepository;
use CMS\Modules\Revision\Service\RevisionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\Support\ModuleDatabaseTestCase;
use Tests\Support\RecordingEventDispatcher;

/**
 * Revision history with the hard 3-revision cap.
 */
#[CoversClass(RevisionService::class)]
final class RevisionServiceTest extends ModuleDatabaseTestCase
{
    private RevisionServiceInterface $revisions;

    private ContentServiceInterface $contents;

    protected function setUp(): void
    {
        parent::setUp();

        $contentRepository = new PdoContentRepository($this->db);

        $this->contents = new ContentService(
            $contentRepository,
            new SlugService($contentRepository),
            new UrlGenerator(new PdoUrlRegistryRepository($this->db)),
            new PlainBlockCompiler(),
            new ContentTypeRegistry(),
            new RecordingEventDispatcher(),
            new FrozenClock(),
        );

        $this->revisions = new RevisionService(
            new PdoRevisionRepository($this->db),
            $this->contents,
            new FrozenClock(),
        );
    }

    private function makeContent(int $siteId, string $title = 'Original'): int
    {
        return (int) $this->contents->create($siteId, [
            'title' => $title,
            'author_id' => 1,
            'content_json' => [['type' => 'paragraph', 'data' => ['text' => 'Original body']]],
        ])->id;
    }

    #[Test]
    public function snapshotCapturesTheCurrentState(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $contentId = $this->makeContent($siteId, 'Snapshot Me');

        $revision = $this->revisions->snapshot($contentId, $siteId, 1);

        self::assertSame($contentId, $revision->contentId);
        self::assertSame('Snapshot Me', $revision->title);
        self::assertSame(1, $revision->revisionNumber);
        self::assertNotNull($revision->contentJson);
    }

    #[Test]
    public function revisionNumbersIncrease(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $contentId = $this->makeContent($siteId);

        $first = $this->revisions->snapshot($contentId, $siteId, 1);
        $second = $this->revisions->snapshot($contentId, $siteId, 1);

        self::assertSame(1, $first->revisionNumber);
        self::assertSame(2, $second->revisionNumber);
    }

    #[Test]
    public function atMostThreeRevisionsAreRetained(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $contentId = $this->makeContent($siteId);

        for ($i = 0; $i < 5; $i++) {
            $this->revisions->snapshot($contentId, $siteId, 1);
        }

        self::assertSame(RevisionServiceInterface::MAX_PER_CONTENT, $this->revisions->countFor($contentId, $siteId));
    }

    #[Test]
    public function tenUpdatesLeaveExactlyThreeRevisions(): void
    {
        // The acceptance criterion from the task pack, stated literally.
        $siteId = $this->createSiteRow('main', 'example.com');
        $contentId = $this->makeContent($siteId);

        for ($i = 1; $i <= 10; $i++) {
            $this->revisions->snapshot($contentId, $siteId, 1);
            $this->contents->update($contentId, $siteId, ['title' => 'Update ' . $i]);
        }

        self::assertSame(3, $this->revisions->countFor($contentId, $siteId));
        self::assertCount(3, $this->revisions->listFor($contentId, $siteId));
    }

    #[Test]
    public function theOldestRevisionIsTheOneRemoved(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $contentId = (int) $this->contents->create($siteId, [
            'title' => 'State A',
            'author_id' => 1,
        ])->id;

        $this->revisions->snapshot($contentId, $siteId, 1);

        $this->contents->update($contentId, $siteId, ['title' => 'State B']);
        $this->revisions->snapshot($contentId, $siteId, 1);

        $this->contents->update($contentId, $siteId, ['title' => 'State C']);
        $this->revisions->snapshot($contentId, $siteId, 1);

        $this->contents->update($contentId, $siteId, ['title' => 'State D']);
        $this->revisions->snapshot($contentId, $siteId, 1);

        $titles = array_map(
            static fn ($revision): string => $revision->title,
            $this->revisions->listFor($contentId, $siteId)
        );

        self::assertCount(3, $titles);
        self::assertNotContains('State A', $titles, 'The oldest revision should have been pruned.');
        self::assertContains('State D', $titles);
    }

    #[Test]
    public function listReturnsNewestFirst(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $contentId = $this->makeContent($siteId);

        $this->revisions->snapshot($contentId, $siteId, 1);
        $this->revisions->snapshot($contentId, $siteId, 1);

        $numbers = array_map(
            static fn ($revision): int => $revision->revisionNumber,
            $this->revisions->listFor($contentId, $siteId)
        );

        self::assertSame([2, 1], $numbers);
    }

    #[Test]
    public function restoreAppliesTheRevisionToTheContent(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $contentId = (int) $this->contents->create($siteId, [
            'title' => 'Wanted Title',
            'author_id' => 1,
            'content_json' => [['type' => 'paragraph', 'data' => ['text' => 'Wanted body']]],
        ])->id;

        $revision = $this->revisions->snapshot($contentId, $siteId, 1);

        $this->contents->update($contentId, $siteId, [
            'title' => 'Damaged Title',
            'content_json' => [['type' => 'paragraph', 'data' => ['text' => 'Damaged body']]],
        ]);

        $restoredId = $this->revisions->restore($revision->id, $siteId, 1);

        self::assertSame($contentId, $restoredId);

        $content = $this->contents->get($contentId, $siteId);

        self::assertSame('Wanted Title', $content->title);
        self::assertSame('<p>Wanted body</p>', $content->contentHtml);
    }

    #[Test]
    public function restoreIsItselfUndoable(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $contentId = (int) $this->contents->create($siteId, [
            'title' => 'First',
            'author_id' => 1,
        ])->id;

        $firstRevision = $this->revisions->snapshot($contentId, $siteId, 1);

        $this->contents->update($contentId, $siteId, ['title' => 'Second']);

        $this->revisions->restore($firstRevision->id, $siteId, 1);

        // A snapshot of 'Second' must now exist, so the restore can be undone.
        $titles = array_map(
            static fn ($revision): string => $revision->title,
            $this->revisions->listFor($contentId, $siteId)
        );

        self::assertContains('Second', $titles);
        self::assertSame('First', $this->contents->get($contentId, $siteId)->title);
    }

    #[Test]
    public function getThrowsForAnUnknownRevision(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(RevisionNotFoundException::class);

        $this->revisions->get(999, $siteId);
    }

    #[Test]
    public function snapshotOfMissingContentThrows(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(ContentMissingException::class);

        $this->revisions->snapshot(999, $siteId, 1);
    }

    #[Test]
    public function revisionsAreIsolatedPerContentRow(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $a = $this->makeContent($siteId, 'A');
        $b = $this->makeContent($siteId, 'B');

        $this->revisions->snapshot($a, $siteId, 1);
        $this->revisions->snapshot($a, $siteId, 1);
        $this->revisions->snapshot($b, $siteId, 1);

        self::assertSame(2, $this->revisions->countFor($a, $siteId));
        self::assertSame(1, $this->revisions->countFor($b, $siteId));
    }

    #[Test]
    public function deletingContentCascadesItsRevisions(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $contentId = $this->makeContent($siteId);

        $this->revisions->snapshot($contentId, $siteId, 1);

        $this->contents->trash($contentId, $siteId);
        $this->contents->purge($contentId, $siteId);

        self::assertSame(0, $this->revisions->countFor($contentId, $siteId));
    }
}
