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
use CMS\Modules\Revision\Contracts\AutosaveServiceInterface;
use CMS\Modules\Revision\Contracts\RevisionServiceInterface;
use CMS\Modules\Revision\Repository\PdoRevisionRepository;
use CMS\Modules\Revision\Service\AutosaveService;
use CMS\Modules\Revision\Service\RevisionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\Support\ModuleDatabaseTestCase;
use Tests\Support\RecordingEventDispatcher;

/**
 * Autosave behaviour.
 *
 * The two invariants that matter: an autosave never changes published content,
 * and it never consumes the revision budget.
 */
#[CoversClass(AutosaveService::class)]
final class AutosaveServiceTest extends ModuleDatabaseTestCase
{
    private AutosaveServiceInterface $autosave;

    private RevisionServiceInterface $revisions;

    private ContentServiceInterface $contents;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $contentRepository = new PdoContentRepository($this->db);
        $this->clock = new FrozenClock();

        $this->contents = new ContentService(
            $contentRepository,
            new SlugService($contentRepository),
            new UrlGenerator(new PdoUrlRegistryRepository($this->db)),
            new PlainBlockCompiler(),
            new ContentTypeRegistry(),
            new RecordingEventDispatcher(),
            $this->clock,
        );

        $revisionRepository = new PdoRevisionRepository($this->db);

        $this->revisions = new RevisionService($revisionRepository, $this->contents, $this->clock);
        $this->autosave = new AutosaveService($revisionRepository, $this->clock);
    }

    private function makeContent(int $siteId, string $title = 'Published Post'): int
    {
        $content = $this->contents->create($siteId, [
            'title' => $title,
            'author_id' => 1,
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'data' => ['text' => 'Published body']]],
        ]);

        return (int) $content->id;
    }

    #[Test]
    public function saveStoresTheBlocks(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $contentId = $this->makeContent($siteId);

        $this->autosave->save($contentId, 7, $siteId, [
            ['type' => 'paragraph', 'data' => ['text' => 'Draft text']],
        ]);

        $stored = $this->autosave->get($contentId, 7, $siteId);

        self::assertIsArray($stored);
        self::assertSame('paragraph', $stored[0]['type']);
    }

    #[Test]
    public function aSecondSaveReplacesRatherThanAccumulates(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $contentId = $this->makeContent($siteId);

        $this->autosave->save($contentId, 7, $siteId, [['type' => 'paragraph', 'data' => ['text' => 'First']]]);
        $this->clock->advanceMinutes(1);
        $this->autosave->save($contentId, 7, $siteId, [['type' => 'paragraph', 'data' => ['text' => 'Second']]]);

        $rows = $this->db->select(
            'SELECT content_json FROM content_autosaves WHERE content_id = :id AND user_id = 7',
            ['id' => $contentId]
        );

        self::assertCount(1, $rows, 'One autosave row per (content, user).');

        $stored = $this->autosave->get($contentId, 7, $siteId);
        self::assertSame('Second', $stored[0]['data']['text']);
    }

    #[Test]
    public function differentUsersKeepSeparateAutosaves(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $contentId = $this->makeContent($siteId);

        $this->autosave->save($contentId, 7, $siteId, [['type' => 'paragraph', 'data' => ['text' => 'User 7']]]);
        $this->autosave->save($contentId, 8, $siteId, [['type' => 'paragraph', 'data' => ['text' => 'User 8']]]);

        self::assertSame('User 7', $this->autosave->get($contentId, 7, $siteId)[0]['data']['text']);
        self::assertSame('User 8', $this->autosave->get($contentId, 8, $siteId)[0]['data']['text']);
    }

    #[Test]
    public function autosaveNeverTouchesTheContentRow(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $contentId = $this->makeContent($siteId);

        $before = $this->contents->get($contentId, $siteId);

        $this->autosave->save($contentId, 7, $siteId, [
            ['type' => 'paragraph', 'data' => ['text' => 'Unsaved draft']],
        ]);

        $after = $this->contents->get($contentId, $siteId);

        self::assertSame($before->title, $after->title);
        self::assertSame($before->contentHtml, $after->contentHtml);
        self::assertSame($before->status, $after->status);
        self::assertStringContainsString('Published body', (string) $after->contentHtml);
    }

    #[Test]
    public function autosaveNeverCreatesARevision(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $contentId = $this->makeContent($siteId);

        for ($i = 0; $i < 5; $i++) {
            $this->autosave->save($contentId, 7, $siteId, [
                ['type' => 'paragraph', 'data' => ['text' => 'Draft ' . $i]],
            ]);
        }

        self::assertSame(0, $this->revisions->countFor($contentId, $siteId));
    }

    #[Test]
    public function getReturnsNullWhenThereIsNoAutosave(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $contentId = $this->makeContent($siteId);

        self::assertNull($this->autosave->get($contentId, 7, $siteId));
        self::assertNull($this->autosave->savedAt($contentId, 7, $siteId));
    }

    #[Test]
    public function savedAtReflectsTheClock(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $contentId = $this->makeContent($siteId);

        $this->autosave->save($contentId, 7, $siteId, [['type' => 'paragraph', 'data' => ['text' => 'x']]]);

        $savedAt = $this->autosave->savedAt($contentId, 7, $siteId);

        self::assertNotNull($savedAt);
        self::assertSame('2026-01-01', $savedAt->format('Y-m-d'));
    }

    #[Test]
    public function clearRemovesTheAutosave(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $contentId = $this->makeContent($siteId);

        $this->autosave->save($contentId, 7, $siteId, [['type' => 'paragraph', 'data' => ['text' => 'temp']]]);
        $this->autosave->clear($contentId, 7, $siteId);

        self::assertNull($this->autosave->get($contentId, 7, $siteId));
    }

    #[Test]
    public function invalidJsonStringIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $contentId = $this->makeContent($siteId);

        $this->expectException(\InvalidArgumentException::class);

        $this->autosave->save($contentId, 7, $siteId, '{not json');
    }

    #[Test]
    public function aJsonStringPayloadIsAccepted(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $contentId = $this->makeContent($siteId);

        $this->autosave->save(
            $contentId,
            7,
            $siteId,
            json_encode([['type' => 'paragraph', 'data' => ['text' => 'From string']]])
        );

        $stored = $this->autosave->get($contentId, 7, $siteId);

        self::assertSame('From string', $stored[0]['data']['text']);
    }

    #[Test]
    public function invalidIdentifiersAreRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(\InvalidArgumentException::class);

        $this->autosave->save(0, 7, $siteId, [['type' => 'paragraph', 'data' => ['text' => 'x']]]);
    }

    #[Test]
    public function autosavesAreIsolatedPerSite(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');

        $contentA = $this->makeContent($first);
        $contentB = $this->makeContent($second);

        $this->autosave->save($contentA, 7, $first, [['type' => 'paragraph', 'data' => ['text' => 'Site one']]]);
        $this->autosave->save($contentB, 7, $second, [['type' => 'paragraph', 'data' => ['text' => 'Site two']]]);

        self::assertSame('Site one', $this->autosave->get($contentA, 7, $first)[0]['data']['text']);
        self::assertSame('Site two', $this->autosave->get($contentB, 7, $second)[0]['data']['text']);
    }

    #[Test]
    public function deletingContentCascadesItsAutosaves(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $contentId = $this->makeContent($siteId);

        $this->autosave->save($contentId, 7, $siteId, [['type' => 'paragraph', 'data' => ['text' => 'x']]]);

        $this->contents->trash($contentId, $siteId);
        $this->contents->purge($contentId, $siteId);

        self::assertNull($this->db->scalar(
            'SELECT content_id FROM content_autosaves WHERE content_id = :id',
            ['id' => $contentId]
        ));
    }
}
