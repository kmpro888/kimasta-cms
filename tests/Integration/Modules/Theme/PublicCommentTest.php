<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Theme;

use CMS\Core\Http\Request;
use CMS\Modules\Comment\Contracts\CommentServiceInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * Approved comments on the article page.
 *
 * An approved comment sat in the database for weeks while the page rendered nothing, because the
 * view model never asked for the thread. The rule that matters here is that only approved
 * comments are visible: pending and spam must never reach a reader.
 */
#[CoversClass(\CMS\Modules\Theme\Rendering\PageViewModelFactory::class)]
final class PublicCommentTest extends AppTestCase
{
    private ?int $authorId = null;

    private function author(): int
    {
        return $this->authorId ??= $this->makeUser('writer', 'contributor');
    }

    private function article(string $slug, string $commentStatus = 'open'): int
    {
        return (int) $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'author_id' => $this->author(),
            'status' => 'published',
            'comment_status' => $commentStatus,
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ])->id;
    }

    private function comment(int $contentId, string $body): int
    {
        return (int) $this->app->container()->get(CommentServiceInterface::class)
            ->submit($this->siteId, $contentId, $this->author(), $body)->id;
    }

    private function page(string $slug): string
    {
        $response = $this->app->handle(Request::create('GET', '/blog/' . $slug, $this->host));

        self::assertSame(200, $response->status());

        return $response->content();
    }

    #[Test]
    public function anApprovedCommentIsShownToTheReader(): void
    {
        $contentId = $this->article('has-approved');
        $commentId = $this->comment($contentId, 'This is visible to everyone.');

        $this->app->container()->get(CommentServiceInterface::class)->approve($commentId, $this->siteId);

        $html = $this->page('has-approved');

        self::assertStringContainsString('class="comments"', $html);
        self::assertStringContainsString('This is visible to everyone.', $html);
        self::assertStringContainsString('One comment', $html);
        self::assertStringContainsString('Writer', $html, 'The author name is shown.');
    }

    #[Test]
    public function aPendingCommentStaysHidden(): void
    {
        $contentId = $this->article('has-pending');
        $this->comment($contentId, 'Not approved yet.');

        $html = $this->page('has-pending');

        self::assertStringNotContainsString('Not approved yet.', $html);
        self::assertStringNotContainsString('class="comment"', $html);
    }

    #[Test]
    public function aSpamCommentStaysHidden(): void
    {
        $contentId = $this->article('has-spam');
        $commentId = $this->comment($contentId, 'Buy cheap things.');

        $this->app->container()->get(CommentServiceInterface::class)->markSpam($commentId, $this->siteId);

        self::assertStringNotContainsString('Buy cheap things.', $this->page('has-spam'));
    }

    #[Test]
    public function anArticleWithNoCommentsAndClosedCommentsRendersNoSection(): void
    {
        $this->article('quiet-closed', 'closed');

        // Nothing to show and nothing to allow: an empty heading would be noise.
        self::assertStringNotContainsString('class="comments"', $this->page('quiet-closed'));
    }

    #[Test]
    public function anArticleWithOpenCommentsAndNoneYetSaysSo(): void
    {
        $this->article('quiet-open');

        $html = $this->page('quiet-open');

        self::assertStringContainsString('class="comments"', $html);
        self::assertStringContainsString('No comments yet', $html);
    }

    #[Test]
    public function theCountReadsNaturallyForOneAndForMany(): void
    {
        $single = $this->article('one-comment');
        $this->app->container()->get(CommentServiceInterface::class)
            ->approve($this->comment($single, 'First.'), $this->siteId);

        self::assertStringContainsString('One comment', $this->page('one-comment'));

        $many = $this->article('two-comments');
        $comments = $this->app->container()->get(CommentServiceInterface::class);
        $comments->approve($this->comment($many, 'First.'), $this->siteId);
        $comments->approve($this->comment($many, 'Second.'), $this->siteId);

        self::assertStringContainsString('2 comments', $this->page('two-comments'));
    }

    #[Test]
    public function commentTextIsEscaped(): void
    {
        $contentId = $this->article('escapes');
        $commentId = $this->comment($contentId, '<script>alert("xss")</script>');

        $this->app->container()->get(CommentServiceInterface::class)->approve($commentId, $this->siteId);

        $html = $this->page('escapes');

        self::assertStringNotContainsString('<script>alert("xss")</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
