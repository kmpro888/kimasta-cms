<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Content;

use CMS\Modules\Content\Contracts\ContentRepositoryInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Contracts\ContentTypeRegistryInterface;
use CMS\Modules\Content\Exceptions\ContentNotFoundException;
use CMS\Modules\Content\Exceptions\ContentNotPurgeableException;
use CMS\Modules\Content\Exceptions\InvalidContentDataException;
use CMS\Modules\Content\Exceptions\InvalidStatusException;
use CMS\Modules\Content\Exceptions\UrlCollisionException;
use CMS\Modules\Content\Repository\PdoContentRepository;
use CMS\Modules\Content\Repository\PdoUrlRegistryRepository;
use CMS\Modules\Content\Service\ContentService;
use CMS\Modules\Content\Service\ContentTypeRegistry;
use CMS\Modules\Content\Service\PlainBlockCompiler;
use CMS\Modules\Content\Service\SlugService;
use CMS\Modules\Content\Service\UrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\Support\ModuleDatabaseTestCase;
use Tests\Support\RecordingEventDispatcher;

/**
 * Content service lifecycle: create, update, trash, purge, status, URLs, events.
 */
#[CoversClass(ContentService::class)]
final class ContentServiceTest extends ModuleDatabaseTestCase
{
    private ContentServiceInterface $service;

    private ContentRepositoryInterface $repository;

    private RecordingEventDispatcher $events;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new PdoContentRepository($this->db);
        $this->events = new RecordingEventDispatcher();
        $this->clock = new FrozenClock();

        $registry = new PdoUrlRegistryRepository($this->db);

        $this->service = new ContentService(
            $this->repository,
            new SlugService($this->repository),
            new UrlGenerator($registry),
            new PlainBlockCompiler(),
            new ContentTypeRegistry(),
            $this->events,
            $this->clock,
        );
    }

    #[Test]
    public function createsAPost(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $content = $this->service->create($siteId, [
            'type' => 'post',
            'title' => 'Hello World',
            'author_id' => 1,
            'content_json' => [['type' => 'paragraph', 'data' => ['text' => 'Body']]],
        ]);

        self::assertSame('hello-world', $content->slug);
        self::assertSame('post', $content->type);
        self::assertSame('draft', $content->status);
        self::assertSame('<p>Body</p>', $content->contentHtml);
        self::assertSame('Body', $content->contentPlain);
        self::assertNotNull($content->contentJson);
    }

    #[Test]
    public function createEmitsContentCreated(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $content = $this->service->create($siteId, [
            'title' => 'Event Test',
            'author_id' => 1,
        ]);

        self::assertSame(['content.created'], $this->events->names());

        $event = $this->events->firstOf('content.created');
        self::assertNotNull($event);
        self::assertSame($siteId, $event->siteId());
        self::assertSame($content->id, $event->get('id'));
        self::assertSame('/blog/event-test', $event->get('path'));
    }

    #[Test]
    public function createRegistersThePermalink(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $content = $this->service->create($siteId, ['title' => 'Permalink Test', 'author_id' => 1]);

        $mapping = (new PdoUrlRegistryRepository($this->db))->resolve($siteId, '/blog/permalink-test');

        self::assertNotNull($mapping);
        self::assertSame('content', $mapping['entity_type']);
        self::assertSame($content->id, $mapping['entity_id']);
    }

    #[Test]
    public function aPageUsesARootPath(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->service->create($siteId, ['type' => 'page', 'title' => 'About Us', 'author_id' => 1]);

        self::assertNotNull((new PdoUrlRegistryRepository($this->db))->resolve($siteId, '/about-us'));
    }

    #[Test]
    public function duplicateTitleGetsASuffixedSlug(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $first = $this->service->create($siteId, ['title' => 'Same Title', 'author_id' => 1]);
        $second = $this->service->create($siteId, ['title' => 'Same Title', 'author_id' => 1]);

        self::assertSame('same-title', $first->slug);
        self::assertSame('same-title-2', $second->slug);
    }

    #[Test]
    public function theSameSlugIsAllowedForADifferentType(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $post = $this->service->create($siteId, ['type' => 'post', 'title' => 'Shared', 'author_id' => 1]);
        $page = $this->service->create($siteId, ['type' => 'page', 'title' => 'Shared', 'author_id' => 1]);

        self::assertSame('shared', $post->slug);
        self::assertSame('shared', $page->slug);
    }

    #[Test]
    public function aCustomContentTypeWorks(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $registry = new ContentTypeRegistry();
        $registry->register('product', ['label' => 'Product']);

        $service = new ContentService(
            $this->repository,
            new SlugService($this->repository),
            new UrlGenerator(new PdoUrlRegistryRepository($this->db)),
            new PlainBlockCompiler(),
            $registry,
            $this->events,
            $this->clock,
        );

        $content = $service->create($siteId, ['type' => 'product', 'title' => 'Widget', 'author_id' => 1]);

        self::assertSame('product', $content->type);

        $mapping = (new PdoUrlRegistryRepository($this->db))->resolve($siteId, '/products/widget');
        self::assertNotNull($mapping);
        self::assertSame($content->id, $mapping['entity_id']);
    }

    #[Test]
    public function anUnknownTypeIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create($siteId, ['type' => 'nonsense', 'title' => 'X', 'author_id' => 1]);
    }

    #[Test]
    public function aMissingTitleIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(InvalidContentDataException::class);

        $this->service->create($siteId, ['author_id' => 1]);
    }

    #[Test]
    public function aMissingAuthorIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(InvalidContentDataException::class);

        $this->service->create($siteId, ['title' => 'No author']);
    }

    #[Test]
    public function anInvalidStatusIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(InvalidStatusException::class);

        $this->service->create($siteId, ['title' => 'X', 'author_id' => 1, 'status' => 'wizard']);
    }

    #[Test]
    public function updatesTitleAndBody(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $content = $this->service->create($siteId, ['title' => 'Original', 'author_id' => 1]);

        $updated = $this->service->update((int) $content->id, $siteId, [
            'title' => 'Revised',
            'content_json' => [['type' => 'paragraph', 'data' => ['text' => 'New body']]],
        ]);

        self::assertSame('Revised', $updated->title);
        self::assertSame('<p>New body</p>', $updated->contentHtml);
        self::assertSame('New body', $updated->contentPlain);
    }

    #[Test]
    public function updateEmitsContentUpdated(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $content = $this->service->create($siteId, ['title' => 'Emit', 'author_id' => 1]);

        $this->events->clear();

        $this->service->update((int) $content->id, $siteId, ['title' => 'Emit 2']);

        self::assertSame(['content.updated'], $this->events->names());
    }

    #[Test]
    public function changingTheSlugMovesThePermalink(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $content = $this->service->create($siteId, ['title' => 'Old Path', 'author_id' => 1]);

        $this->service->update((int) $content->id, $siteId, ['slug' => 'new-path']);

        $registry = new PdoUrlRegistryRepository($this->db);

        self::assertNull($registry->resolve($siteId, '/blog/old-path'));
        self::assertNotNull($registry->resolve($siteId, '/blog/new-path'));
    }

    #[Test]
    public function updatingANonExistentRowThrows(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(ContentNotFoundException::class);

        $this->service->update(999, $siteId, ['title' => 'Ghost']);
    }

    #[Test]
    public function getReturnsTheRow(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $content = $this->service->create($siteId, ['title' => 'Fetch Me', 'author_id' => 1]);

        self::assertSame($content->id, $this->service->get((int) $content->id, $siteId)->id);
    }

    #[Test]
    public function getThrowsForAnUnknownId(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(ContentNotFoundException::class);

        $this->service->get(4242, $siteId);
    }

    #[Test]
    public function getBySlugResolves(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $content = $this->service->create($siteId, ['title' => 'By Slug', 'author_id' => 1]);

        self::assertSame($content->id, $this->service->getBySlug('by-slug', 'post', $siteId)->id);
    }

    #[Test]
    public function trashMarksTheRowAndDropsThePermalink(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $content = $this->service->create($siteId, ['title' => 'Trash Me', 'author_id' => 1]);

        self::assertTrue($this->service->trash((int) $content->id, $siteId));

        $reloaded = $this->service->get((int) $content->id, $siteId);
        self::assertSame('trash', $reloaded->status);

        self::assertNull((new PdoUrlRegistryRepository($this->db))->resolve($siteId, '/blog/trash-me'));
    }

    #[Test]
    public function trashEmitsContentDeleted(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $content = $this->service->create($siteId, ['title' => 'Gone', 'author_id' => 1]);

        $this->events->clear();
        $this->service->trash((int) $content->id, $siteId);

        self::assertSame(['content.deleted'], $this->events->names());
    }

    #[Test]
    public function trashReturnsFalseForAnUnknownRow(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        self::assertFalse($this->service->trash(999, $siteId));
    }

    #[Test]
    public function purgeRequiresTheRowToBeTrashedFirst(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $content = $this->service->create($siteId, ['title' => 'Not Trashed', 'author_id' => 1]);

        $this->expectException(ContentNotPurgeableException::class);

        $this->service->purge((int) $content->id, $siteId);
    }

    #[Test]
    public function purgeRemovesATrashedRow(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $content = $this->service->create($siteId, ['title' => 'Purge Me', 'author_id' => 1]);

        $this->service->trash((int) $content->id, $siteId);

        self::assertTrue($this->service->purge((int) $content->id, $siteId));

        $this->expectException(ContentNotFoundException::class);
        $this->service->get((int) $content->id, $siteId);
    }

    #[Test]
    public function setStatusPublishesAndStampsTheDate(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $content = $this->service->create($siteId, ['title' => 'Publish Me', 'author_id' => 1]);

        self::assertNull($content->publishedAt);

        $published = $this->service->setStatus((int) $content->id, $siteId, 'published');

        self::assertSame('published', $published->status);
        self::assertNotNull($published->publishedAt);
        self::assertTrue($published->isPubliclyVisible($this->clock->now()));
    }

    #[Test]
    public function setStatusRejectsAnUnknownStatus(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $content = $this->service->create($siteId, ['title' => 'X', 'author_id' => 1]);

        $this->expectException(InvalidStatusException::class);

        $this->service->setStatus((int) $content->id, $siteId, 'nonsense');
    }

    #[Test]
    public function aStatusOfPublishedAtCreateStampsPublishDate(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $content = $this->service->create($siteId, [
            'title' => 'Direct Publish',
            'author_id' => 1,
            'status' => 'published',
        ]);

        self::assertNotNull($content->publishedAt);
    }

    #[Test]
    public function anExplicitExcerptIsKept(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $content = $this->service->create($siteId, [
            'title' => 'With Excerpt',
            'author_id' => 1,
            'excerpt' => 'A hand written summary.',
            'content_json' => [['type' => 'paragraph', 'data' => ['text' => 'Long body text']]],
        ]);

        self::assertSame('A hand written summary.', $content->excerpt);
    }

    #[Test]
    public function anExcerptIsDerivedFromTheBodyWhenOmitted(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $content = $this->service->create($siteId, [
            'title' => 'No Excerpt',
            'author_id' => 1,
            'content_json' => [['type' => 'paragraph', 'data' => ['text' => 'Derived summary text']]],
        ]);

        self::assertSame('Derived summary text', $content->excerpt);
    }

    #[Test]
    public function invalidJsonStringIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(InvalidContentDataException::class);

        $this->service->create($siteId, [
            'title' => 'Bad Json',
            'author_id' => 1,
            'content_json' => '{not valid json',
        ]);
    }

    #[Test]
    public function contentJsonStringIsAccepted(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $content = $this->service->create($siteId, [
            'title' => 'Json String',
            'author_id' => 1,
            'content_json' => json_encode([['type' => 'paragraph', 'data' => ['text' => 'From string']]]),
        ]);

        self::assertSame('<p>From string</p>', $content->contentHtml);
    }

    #[Test]
    public function aUrlCollisionIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $registry = new PdoUrlRegistryRepository($this->db);
        $registry->put($siteId, '/blog/taken-path', 'content', 999);

        $generator = new UrlGenerator($registry);

        $this->expectException(UrlCollisionException::class);

        $generator->register($siteId, '/blog/taken-path', 'content', 1);
    }
}
