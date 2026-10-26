<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Taxonomy;

use CMS\Core\Database\QueryProfiler;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Repository\PdoContentRepository;
use CMS\Modules\Content\Repository\PdoUrlRegistryRepository;
use CMS\Modules\Content\Service\ContentService;
use CMS\Modules\Content\Service\ContentTypeRegistry;
use CMS\Modules\Content\Service\PlainBlockCompiler;
use CMS\Modules\Content\Service\SlugService;
use CMS\Modules\Content\Service\UrlGenerator;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use CMS\Modules\Taxonomy\Exceptions\DuplicateTermException;
use CMS\Modules\Taxonomy\Exceptions\TermNotFoundException;
use CMS\Modules\Taxonomy\Repository\PdoTaxonomyRepository;
use CMS\Modules\Taxonomy\Repository\PdoTermRepository;
use CMS\Modules\Taxonomy\Service\TaxonomyRegistry;
use CMS\Modules\Taxonomy\Service\TaxonomyService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\Support\ModuleDatabaseTestCase;
use Tests\Support\RecordingEventDispatcher;

/**
 * Taxonomy and term behaviour against a real database.
 */
#[CoversClass(TaxonomyService::class)]
final class TaxonomyServiceTest extends ModuleDatabaseTestCase
{
    private TaxonomyServiceInterface $service;

    private ContentServiceInterface $contents;

    private RecordingEventDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $terms = new PdoTermRepository($this->db);
        $this->events = new RecordingEventDispatcher();

        $this->service = new TaxonomyService(
            new TaxonomyRegistry(),
            new PdoTaxonomyRepository($this->db),
            $terms,
            $this->events,
            new FrozenClock(),
        );

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
    }

    private function makeContent(int $siteId, string $title = 'A Post'): int
    {
        return (int) $this->contents->create($siteId, [
            'title' => $title,
            'author_id' => 1,
        ])->id;
    }

    #[Test]
    public function seedingCreatesBothDefaultTaxonomies(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->service->seedDefaults($siteId);

        $rows = (new PdoTaxonomyRepository($this->db))->listBySite($siteId);

        self::assertArrayHasKey('category', $rows);
        self::assertArrayHasKey('tag', $rows);
        self::assertTrue($rows['category']['hierarchical']);
        self::assertFalse($rows['tag']['hierarchical']);
    }

    #[Test]
    public function ensureTaxonomyIsIdempotent(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $first = $this->service->ensureTaxonomy($siteId, 'category');
        $second = $this->service->ensureTaxonomy($siteId, 'category');

        self::assertSame($first, $second);
    }

    #[Test]
    public function createsATerm(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $term = $this->service->createTerm($siteId, 'category', 'Technology');

        self::assertSame('Technology', $term->name);
        self::assertSame('technology', $term->slug);
        self::assertSame(0, $term->contentCount);
        self::assertTrue($term->isRoot());
    }

    #[Test]
    public function createTermEmitsTermCreated(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->service->createTerm($siteId, 'category', 'Emitted');

        self::assertSame(['term.created'], $this->events->names());

        $event = $this->events->firstOf('term.created');
        self::assertNotNull($event);
        self::assertSame('category', $event->get('taxonomy'));
    }

    #[Test]
    public function duplicateSlugIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->service->createTerm($siteId, 'category', 'News');

        $this->expectException(DuplicateTermException::class);

        $this->service->createTerm($siteId, 'category', 'news', null, null, 'news');
    }

    #[Test]
    public function aDuplicateNameGetsASuffixedSlugInsteadOfFailing(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $first = $this->service->createTerm($siteId, 'category', 'News');
        $second = $this->service->createTerm($siteId, 'category', 'News');

        self::assertSame('news', $first->slug);
        self::assertSame('news-2', $second->slug);
    }

    #[Test]
    public function theSameSlugMayExistInTwoTaxonomies(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $category = $this->service->createTerm($siteId, 'category', 'Shared');
        $tag = $this->service->createTerm($siteId, 'tag', 'Shared');

        self::assertSame('shared', $category->slug);
        self::assertSame('shared', $tag->slug);
        self::assertNotSame($category->id, $tag->id);
    }

    #[Test]
    public function categoriesSupportParentChild(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $parent = $this->service->createTerm($siteId, 'category', 'Technology');
        $child = $this->service->createTerm($siteId, 'category', 'Programming', null, (int) $parent->id);

        self::assertSame($parent->id, $child->parentId);

        $roots = $this->service->listTerms($siteId, 'category');
        self::assertCount(1, $roots);
        self::assertSame($parent->id, $roots[0]->id);

        $children = $this->service->listTerms($siteId, 'category', (int) $parent->id);
        self::assertCount(1, $children);
        self::assertSame($child->id, $children[0]->id);
    }

    #[Test]
    public function aFlatTaxonomyRejectsParents(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $tag = $this->service->createTerm($siteId, 'tag', 'Root');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->createTerm($siteId, 'tag', 'Child', null, (int) $tag->id);
    }

    #[Test]
    public function listTermsForAFlatTaxonomyReturnsEverything(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->service->createTerm($siteId, 'tag', 'One');
        $this->service->createTerm($siteId, 'tag', 'Two');

        self::assertCount(2, $this->service->listTerms($siteId, 'tag'));
    }

    #[Test]
    public function attachIncrementsTheCounterOnce(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $term = $this->service->createTerm($siteId, 'tag', 'Attached');
        $contentId = $this->makeContent($siteId);

        $this->service->attach($contentId, (int) $term->id, $siteId);
        // Repeating the call must not double count.
        $this->service->attach($contentId, (int) $term->id, $siteId);

        self::assertSame(1, $this->service->getTerm((int) $term->id, $siteId)->contentCount);
    }

    #[Test]
    public function detachDecrementsTheCounter(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $term = $this->service->createTerm($siteId, 'tag', 'Detached');
        $contentId = $this->makeContent($siteId);

        $this->service->attach($contentId, (int) $term->id, $siteId);
        $this->service->detach($contentId, (int) $term->id, $siteId);

        self::assertSame(0, $this->service->getTerm((int) $term->id, $siteId)->contentCount);
    }

    #[Test]
    public function detachingSomethingNotAttachedDoesNotGoNegative(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $term = $this->service->createTerm($siteId, 'tag', 'Untouched');
        $contentId = $this->makeContent($siteId);

        $this->service->detach($contentId, (int) $term->id, $siteId);

        self::assertSame(0, $this->service->getTerm((int) $term->id, $siteId)->contentCount);
    }

    #[Test]
    public function syncReplacesTheWholeTermSetAndReconcilesCounters(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $a = $this->service->createTerm($siteId, 'tag', 'A');
        $b = $this->service->createTerm($siteId, 'tag', 'B');
        $c = $this->service->createTerm($siteId, 'tag', 'C');

        $contentId = $this->makeContent($siteId);

        $this->service->sync($contentId, $siteId, [(int) $a->id, (int) $b->id]);
        self::assertSame(1, $this->service->getTerm((int) $a->id, $siteId)->contentCount);
        self::assertSame(1, $this->service->getTerm((int) $b->id, $siteId)->contentCount);

        // Drop B, add C.
        $this->service->sync($contentId, $siteId, [(int) $a->id, (int) $c->id]);

        self::assertSame(1, $this->service->getTerm((int) $a->id, $siteId)->contentCount);
        self::assertSame(0, $this->service->getTerm((int) $b->id, $siteId)->contentCount);
        self::assertSame(1, $this->service->getTerm((int) $c->id, $siteId)->contentCount);
    }

    #[Test]
    public function syncToAnEmptySetDetachesEverything(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $term = $this->service->createTerm($siteId, 'tag', 'Only');
        $contentId = $this->makeContent($siteId);

        $this->service->sync($contentId, $siteId, [(int) $term->id]);
        $this->service->sync($contentId, $siteId, []);

        self::assertSame(0, $this->service->getTerm((int) $term->id, $siteId)->contentCount);
    }

    #[Test]
    public function deletingATermDetachesItButKeepsContent(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $term = $this->service->createTerm($siteId, 'tag', 'Doomed');
        $contentId = $this->makeContent($siteId, 'Survives');

        $this->service->attach($contentId, (int) $term->id, $siteId);

        self::assertTrue($this->service->deleteTerm((int) $term->id, $siteId));

        // The content row is untouched.
        self::assertSame('Survives', $this->contents->get($contentId, $siteId)->title);

        // And the association is gone.
        self::assertSame([], $this->service->termsForContents($siteId, [$contentId])[$contentId]);
    }

    #[Test]
    public function deleteTermEmitsTermDeleted(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $term = $this->service->createTerm($siteId, 'tag', 'Gone');

        $this->events->clear();
        $this->service->deleteTerm((int) $term->id, $siteId);

        self::assertSame(['term.deleted'], $this->events->names());
    }

    #[Test]
    public function updatesNameAndDescription(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $term = $this->service->createTerm($siteId, 'category', 'Old Name');

        $updated = $this->service->updateTerm((int) $term->id, $siteId, [
            'name' => 'New Name',
            'description' => 'A description',
        ]);

        self::assertSame('New Name', $updated->name);
        self::assertSame('A description', $updated->description);
        // The slug is stable unless explicitly changed.
        self::assertSame('old-name', $updated->slug);
    }

    #[Test]
    public function updateEmitsTermUpdated(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $term = $this->service->createTerm($siteId, 'category', 'Before');

        $this->events->clear();
        $this->service->updateTerm((int) $term->id, $siteId, ['name' => 'After']);

        self::assertSame(['term.updated'], $this->events->names());
    }

    #[Test]
    public function aTermCannotBeItsOwnParent(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $term = $this->service->createTerm($siteId, 'category', 'Self');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->updateTerm((int) $term->id, $siteId, ['parent_id' => $term->id]);
    }

    #[Test]
    public function getTermThrowsForAnUnknownId(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(TermNotFoundException::class);

        $this->service->getTerm(999, $siteId);
    }

    #[Test]
    public function batchLoadingCostsTwoQueriesRegardlessOfPageSize(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $term = $this->service->createTerm($siteId, 'tag', 'Batch');

        $contentIds = [];

        for ($i = 1; $i <= 10; $i++) {
            $contentId = $this->makeContent($siteId, 'Post ' . $i);
            $this->service->attach($contentId, (int) $term->id, $siteId);
            $contentIds[] = $contentId;
        }

        $profiler = new QueryProfiler();

        $this->db->profiler($profiler);

        try {
            $result = $this->service->termsForContents($siteId, $contentIds);
        } finally {
            $this->db->profiler(null);
        }

        // One query for associations, one for the term rows. This is the batch
        // loader the task pack requires: no per-post term query.
        self::assertSame(2, $profiler->count());
        self::assertCount(10, $result);
        self::assertSame('Batch', $result[$contentIds[0]][0]->name);
    }

    #[Test]
    public function termsForContentsReturnsAnEntryForEveryRequestedId(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $withTerm = $this->makeContent($siteId, 'With');
        $without = $this->makeContent($siteId, 'Without');

        $term = $this->service->createTerm($siteId, 'tag', 'Solo');
        $this->service->attach($withTerm, (int) $term->id, $siteId);

        $result = $this->service->termsForContents($siteId, [$withTerm, $without]);

        self::assertCount(1, $result[$withTerm]);
        self::assertSame([], $result[$without]);
    }

    #[Test]
    public function termsForAnEmptyIdListCostsNoQueries(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $profiler = new QueryProfiler();
        $this->db->profiler($profiler);

        try {
            $result = $this->service->termsForContents($siteId, []);
        } finally {
            $this->db->profiler(null);
        }

        self::assertSame([], $result);
        self::assertSame(0, $profiler->count());
    }
}
