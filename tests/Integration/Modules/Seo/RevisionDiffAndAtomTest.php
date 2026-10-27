<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Seo;

use CMS\Core\Http\Request;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Revision\Contracts\RevisionServiceInterface;
use CMS\Modules\Seo\Service\FeedService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * Revision diffs and the Atom feed.
 *
 * Two gaps that were both a matter of "the data exists, nobody can read it":
 *
 *  - revisions were written and listed, but reviewing one meant restoring it and looking — there was
 *    no way to see what changed;
 *  - the site had an RSS feed, which carries no stable entry ids. Atom is the format a reader can
 *    resubscribe to, and it was simply absent.
 */
final class RevisionDiffAndAtomTest extends AdminTestCase
{
    private function article(string $slug): int
    {
        $content = $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => 'Diff article',
            'slug' => $slug,
            'excerpt' => 'First excerpt.',
            'author_id' => $this->makeUser('diff-writer', 'contributor'),
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'First draft.']]],
        ]);

        return (int) $content->id;
    }

    private function revisions(): RevisionServiceInterface
    {
        return $this->app->container()->get(RevisionServiceInterface::class);
    }

    #[Test]
    public function aDiffNamesTheBlockThatChanged(): void
    {
        $contentId = $this->article('diff-blocks');
        $author = (int) $this->makeUser('diff-author', 'contributor');

        $this->revisions()->snapshot($contentId, $this->siteId, $author);

        $this->app->container()->get(ContentServiceInterface::class)->update($contentId, $this->siteId, [
            'title' => 'Diff article, revised',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Second draft.']]],
        ]);

        $second = $this->revisions()->snapshot($contentId, $this->siteId, $author);

        $diff = $this->revisions()->diff((int) $second->id, $this->siteId);

        self::assertTrue($diff['changed']);
        self::assertSame('Diff article, revised', $diff['title']['to']);
        self::assertCount(1, $diff['blocks']);
        self::assertSame('changed', $diff['blocks'][0]['status']);
        self::assertSame('Second draft.', $diff['blocks'][0]['to']);
    }

    #[Test]
    public function anIdenticalRevisionReportsNoChange(): void
    {
        $contentId = $this->article('diff-identical');
        $author = (int) $this->makeUser('diff-author-2', 'contributor');

        $this->revisions()->snapshot($contentId, $this->siteId, $author);
        $second = $this->revisions()->snapshot($contentId, $this->siteId, $author);

        $diff = $this->revisions()->diff((int) $second->id, $this->siteId);

        self::assertFalse($diff['changed']);
        self::assertSame([], $diff['blocks']);
    }

    #[Test]
    public function theDiffScreenShowsTheBeforeAndAfterText(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $contentId = $this->article('diff-screen');
        $author = (int) $this->makeUser('diff-author-3', 'contributor');

        $this->revisions()->snapshot($contentId, $this->siteId, $author);

        $this->app->container()->get(ContentServiceInterface::class)->update($contentId, $this->siteId, [
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Readable change.']]],
        ]);

        $second = $this->revisions()->snapshot($contentId, $this->siteId, $author);

        $html = $this->body($this->get('/admin/content/' . $contentId . '/revisions/' . $second->id));

        self::assertStringContainsString('Compare revision', $html);
        self::assertStringContainsString('Readable change.', $html);
        self::assertStringContainsString('<del>', $html);
        self::assertStringContainsString('<ins>', $html);
    }

    #[Test]
    public function theAtomFeedIsServedWithItsOwnContentType(): void
    {
        $this->article('atom-entry');

        // The feed is a generated file; build it the way cron would.
        $this->app->container()->get(FeedService::class)->generateAtom($this->siteId);

        $response = $this->app->handle(Request::create('GET', '/atom.xml', $this->host));
        $xml = $response->content();

        self::assertSame(200, $response->status());
        self::assertSame('application/atom+xml; charset=utf-8', $response->header('Content-Type'));

        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml), 'The Atom feed must be valid XML.');

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('a', 'http://www.w3.org/2005/Atom');

        self::assertSame(1, $xpath->query('/a:feed')->length);
        self::assertSame(1, $xpath->query('/a:feed/a:id')->length, 'Atom wants a feed id.');
        self::assertSame(1, $xpath->query('/a:feed/a:updated')->length);
        self::assertGreaterThan(0, $xpath->query('/a:feed/a:entry')->length);

        foreach ($xpath->query('/a:feed/a:entry') as $entry) {
            self::assertSame(1, $xpath->query('a:id', $entry)->length, 'Every entry needs a stable id.');
            self::assertSame(1, $xpath->query('a:updated', $entry)->length);
            self::assertSame(1, $xpath->query('a:link[@rel="alternate"]', $entry)->length);
        }
    }

    #[Test]
    public function thePageAdvertisesTheAtomFeed(): void
    {
        $this->article('atom-link');

        $html = $this->body($this->app->handle(Request::create('GET', '/blog/atom-link', $this->host)));

        self::assertStringContainsString('type="application/atom+xml"', $html);
        self::assertStringContainsString('href="/atom.xml"', $html);
    }
}
