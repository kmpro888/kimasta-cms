<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Theme;

use CMS\Core\Http\Request;
use CMS\Core\Security\RateLimiter;
use CMS\Modules\Comment\Contracts\CommentServiceInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * Anonymous visitors posting comments.
 *
 * The platform has no reader account, so a comment carries a typed name and `user_id = 0`. Every
 * submission lands in the moderation queue as `pending`: unreviewed public text is an injection
 * surface with extra steps, so the form exists *because* nothing is published automatically.
 *
 * The defences are a honeypot and a rate limit, and both are tested for what they must not do —
 * store a comment — not merely for their status code.
 */
#[CoversClass(\CMS\Modules\Theme\Http\PublicCommentController::class)]
final class PublicCommentPostTest extends AppTestCase
{
    private int $contentId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // The limiter is filesystem-backed and shared between runs; a stale bucket would make the
        // rate-limit test pass or fail depending on what ran before it.
        $limiter = $this->app->container()->get(RateLimiter::class);

        foreach (['127.0.0.1', 'unknown'] as $address) {
            $limiter->clear('comment:' . $this->siteId . ':' . md5($address));
        }
    }

    private function publishedArticle(string $slug = 'comment-target', string $commentStatus = 'open'): int
    {
        $content = $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => 'Comment Target',
            'slug' => $slug,
            'author_id' => $this->makeUser('writer', 'contributor'),
            'status' => 'published',
            'comment_status' => $commentStatus,
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);

        return $this->contentId = (int) $content->id;
    }

    private function submit(array $fields): \CMS\Core\Http\Response
    {
        return $this->app->handle(Request::create(
            'POST',
            '/comment',
            $this->host,
            [],
            $fields,
        ));
    }

    private function thread(int $contentId): array
    {
        return $this->app->container()->get(CommentServiceInterface::class)->thread($this->siteId, $contentId);
    }

    private function pendingCount(): int
    {
        return $this->app->container()->get(CommentServiceInterface::class)
            ->countForSite($this->siteId, 'pending');
    }

    #[Test]
    public function aVisitorCanPostAndItWaitsForModeration(): void
    {
        $id = $this->publishedArticle();

        $response = $this->submit([
            'content_id' => $id,
            'author_name' => 'A Reader',
            'body' => 'This is a thoughtful comment.',
        ]);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('waiting for a moderator', $response->content());
        self::assertSame('no-store', $response->header('Cache-Control'));

        self::assertSame([], $this->thread($id), 'A pending comment must not be in the public thread.');
        self::assertSame(1, $this->pendingCount(), 'It must be in the moderation queue.');

        $pending = $this->app->container()->get(CommentServiceInterface::class)
            ->listForSite($this->siteId, 'pending');

        self::assertSame('A Reader', $pending[0]->authorName);
        self::assertSame(0, (int) $pending[0]->userId, 'A guest is marked with user_id 0.');
    }

    #[Test]
    public function theHoneypotIsIgnoredSilently(): void
    {
        $id = $this->publishedArticle();

        $response = $this->submit([
            'content_id' => $id,
            'author_name' => 'A Robot',
            'body' => 'Buy cheap things.',
            'website' => 'http://spam.example',
        ]);

        // The same page, so a script learns nothing about being caught.
        self::assertSame(200, $response->status());
        self::assertStringContainsString('waiting for a moderator', $response->content());
        self::assertSame(0, $this->pendingCount(), 'A honeypot hit must not be stored.');
    }

    #[Test]
    public function aClosedThreadRefusesTheComment(): void
    {
        $id = $this->publishedArticle('closed-target', 'closed');

        $response = $this->submit([
            'content_id' => $id,
            'author_name' => 'A Reader',
            'body' => 'A comment on a closed thread.',
        ]);

        self::assertSame(409, $response->status());
        self::assertStringContainsString('not accepting comments', $response->content());
        self::assertSame(0, $this->pendingCount());
    }

    #[Test]
    public function tooShortABodyIsRefused(): void
    {
        $id = $this->publishedArticle();

        $response = $this->submit(['content_id' => $id, 'author_name' => 'A Reader', 'body' => 'x']);

        self::assertSame(422, $response->status());
        self::assertSame(0, $this->pendingCount());
    }

    #[Test]
    public function aMissingNameIsRefused(): void
    {
        $id = $this->publishedArticle();

        $response = $this->submit(['content_id' => $id, 'author_name' => ' ', 'body' => 'A fine comment.']);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('name is required', $response->content());
        self::assertSame(0, $this->pendingCount());
    }

    #[Test]
    public function oneAddressCannotFillTheQueue(): void
    {
        $id = $this->publishedArticle();

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->submit([
                'content_id' => $id,
                'author_name' => 'Persistent Reader',
                'body' => 'Comment number ' . $i . ' of many.',
            ]);

            self::assertSame(200, $response->status(), 'Comment ' . $i . ' is within the limit.');
        }

        $blocked = $this->submit([
            'content_id' => $id,
            'author_name' => 'Persistent Reader',
            'body' => 'Comment number six.',
        ]);

        self::assertSame(429, $blocked->status());
        self::assertStringContainsString('Too many comments', $blocked->content());
        self::assertSame(5, $this->pendingCount(), 'The sixth must not be stored.');
    }

    #[Test]
    public function anotherSitesArticleIsA404(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        $foreign = $this->app->container()->get(ContentServiceInterface::class)->create($otherSite, [
            'type' => 'post',
            'title' => 'Theirs',
            'slug' => 'theirs',
            'author_id' => $this->makeUser('theirs', 'contributor'),
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);

        $response = $this->submit([
            'content_id' => (int) $foreign->id,
            'author_name' => 'A Reader',
            'body' => 'Commenting across tenants.',
        ]);

        self::assertSame(404, $response->status());
        self::assertSame(0, $this->pendingCount());
    }

    #[Test]
    public function theFormIsOnTheArticlePage(): void
    {
        $this->publishedArticle();

        $html = $this->app->handle(Request::create('GET', '/blog/comment-target', $this->host))->content();

        self::assertStringContainsString('action="/comment"', $html);
        self::assertStringContainsString('name="author_name"', $html);
        self::assertStringContainsString('once a moderator has approved it', $html);
        self::assertStringContainsString('name="website"', $html, 'The honeypot is in the markup.');
    }
}
