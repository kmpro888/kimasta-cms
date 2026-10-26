<?php

declare(strict_types=1);

namespace Tests\Security\Modules\Taxonomy;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Repository\PdoContentRepository;
use CMS\Modules\Content\Repository\PdoUrlRegistryRepository;
use CMS\Modules\Content\Service\ContentService;
use CMS\Modules\Content\Service\ContentTypeRegistry;
use CMS\Modules\Content\Service\PlainBlockCompiler;
use CMS\Modules\Content\Service\SlugService;
use CMS\Modules\Content\Service\UrlGenerator;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use CMS\Modules\Taxonomy\Repository\PdoTaxonomyRepository;
use CMS\Modules\Taxonomy\Repository\PdoTermRepository;
use CMS\Modules\Taxonomy\Service\TaxonomyRegistry;
use CMS\Modules\Taxonomy\Service\TaxonomyService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\Support\ModuleDatabaseTestCase;
use Tests\Support\RecordingEventDispatcher;

/**
 * Cross-tenant taxonomy isolation.
 */
final class TaxonomyIsolationTest extends ModuleDatabaseTestCase
{
    private TaxonomyServiceInterface $service;

    private ContentServiceInterface $contents;

    private int $siteA;

    private int $siteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TaxonomyService(
            new TaxonomyRegistry(),
            new PdoTaxonomyRepository($this->db),
            new PdoTermRepository($this->db),
            new RecordingEventDispatcher(),
            new FrozenClock(),
        );

        $repository = new PdoContentRepository($this->db);

        $this->contents = new ContentService(
            $repository,
            new SlugService($repository),
            new UrlGenerator(new PdoUrlRegistryRepository($this->db)),
            new PlainBlockCompiler(),
            new ContentTypeRegistry(),
            new RecordingEventDispatcher(),
            new FrozenClock(),
        );

        $this->siteA = $this->createSiteRow('alpha', 'alpha.example');
        $this->siteB = $this->createSiteRow('beta', 'beta.example');
    }

    private function makeContent(int $siteId, string $title): int
    {
        return (int) $this->contents->create($siteId, ['title' => $title, 'author_id' => 1])->id;
    }

    #[Test]
    public function aTermFromAnotherSiteIsNotVisible(): void
    {
        $term = $this->service->createTerm($this->siteA, 'tag', 'Alpha Tag');

        self::assertNull(
            $this->db->scalar(
                'SELECT id FROM terms WHERE id = :id AND site_id = :site_id',
                ['id' => (int) $term->id, 'site_id' => $this->siteB]
            )
        );

        self::assertSame((int) $term->id, (int) $this->db->scalar(
            'SELECT id FROM terms WHERE id = :id AND site_id = :site_id',
            ['id' => (int) $term->id, 'site_id' => $this->siteA]
        ));
    }

    #[Test]
    public function getTermRejectsAForeignTermId(): void
    {
        $term = $this->service->createTerm($this->siteA, 'tag', 'Alpha Only');

        $this->expectException(\CMS\Modules\Taxonomy\Exceptions\TermNotFoundException::class);

        $this->service->getTerm((int) $term->id, $this->siteB);
    }

    #[Test]
    public function deletingAForeignTermDoesNothing(): void
    {
        $term = $this->service->createTerm($this->siteA, 'tag', 'Protected');

        self::assertFalse($this->service->deleteTerm((int) $term->id, $this->siteB));
        self::assertNotNull($this->service->getTerm((int) $term->id, $this->siteA));
    }

    #[Test]
    public function theSameSlugMayExistOnTwoSites(): void
    {
        $alpha = $this->service->createTerm($this->siteA, 'tag', 'Shared Term');
        $beta = $this->service->createTerm($this->siteB, 'tag', 'Shared Term');

        self::assertSame('shared-term', $alpha->slug);
        self::assertSame('shared-term', $beta->slug);
        self::assertNotSame($alpha->id, $beta->id);
    }

    #[Test]
    public function aParentFromAnotherSiteIsRejected(): void
    {
        $foreignParent = $this->service->createTerm($this->siteA, 'category', 'Alpha Parent');

        $this->expectException(\CMS\Modules\Taxonomy\Exceptions\TermNotFoundException::class);

        $this->service->createTerm(
            $this->siteB,
            'category',
            'Child',
            null,
            (int) $foreignParent->id
        );
    }

    #[Test]
    public function aContentRowCannotBeTaggedWithAForeignTerm(): void
    {
        $foreignTerm = $this->service->createTerm($this->siteA, 'tag', 'Alpha Tag');
        $contentB = $this->makeContent($this->siteB, 'Beta Post');

        $this->expectException(\CMS\Modules\Taxonomy\Exceptions\TermNotFoundException::class);

        $this->service->attach($contentB, (int) $foreignTerm->id, $this->siteB);
    }

    #[Test]
    public function theCounterIsNotPerturbedByAnotherSite(): void
    {
        $alphaTerm = $this->service->createTerm($this->siteA, 'tag', 'Counted');
        $contentA = $this->makeContent($this->siteA, 'Alpha Post');

        $this->service->attach($contentA, (int) $alphaTerm->id, $this->siteA);

        // Site B cannot see or affect that counter.
        self::assertSame(1, $this->service->getTerm((int) $alphaTerm->id, $this->siteA)->contentCount);
        self::assertSame(
            0,
            (int) ($this->db->scalar(
                'SELECT content_count FROM terms WHERE site_id = :site_id',
                ['site_id' => $this->siteB]
            ) ?? 0)
        );
    }

    #[Test]
    public function batchLoadingIsPartitionedBySite(): void
    {
        $alphaTerm = $this->service->createTerm($this->siteA, 'tag', 'Alpha');
        $betaTerm = $this->service->createTerm($this->siteB, 'tag', 'Beta');

        $contentA = $this->makeContent($this->siteA, 'A');
        $contentB = $this->makeContent($this->siteB, 'B');

        $this->service->attach($contentA, (int) $alphaTerm->id, $this->siteA);
        $this->service->attach($contentB, (int) $betaTerm->id, $this->siteB);

        $alpha = $this->service->termsForContents($this->siteA, [$contentA]);
        $beta = $this->service->termsForContents($this->siteB, [$contentB]);

        self::assertSame('Alpha', $alpha[$contentA][0]->name);
        self::assertSame('Beta', $beta[$contentB][0]->name);

        // Asking site B about site A's content yields nothing at all.
        self::assertSame([], $this->service->termsForContents($this->siteB, [$contentA])[$contentA]);
    }

    #[Test]
    public function deletingASiteCascadesItsTaxonomyData(): void
    {
        $term = $this->service->createTerm($this->siteA, 'tag', 'Doomed');
        $contentA = $this->makeContent($this->siteA, 'Doomed Post');
        $this->service->attach($contentA, (int) $term->id, $this->siteA);

        $this->db->statement('DELETE FROM sites WHERE id = :id', ['id' => $this->siteA]);

        self::assertNull($this->db->scalar(
            'SELECT id FROM terms WHERE id = :id',
            ['id' => (int) $term->id]
        ));
        self::assertNull($this->db->scalar(
            'SELECT site_id FROM content_terms WHERE site_id = :site_id',
            ['site_id' => $this->siteA]
        ));
    }
}
