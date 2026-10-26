<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * Who may see and do what.
 *
 * The workflow buttons are computed from `allowedTransitions()` and `requiredCapability()`, so
 * the screens cannot offer a step the server refuses. These tests check the enforcement, not
 * the rendering.
 */
#[CoversClass(\CMS\Modules\Admin\Http\Controller\ContentController::class)]
final class AdminAuthorizationTest extends AdminTestCase
{
    private function draft(string $title, int $authorId, ?int $siteId = null): int
    {
        $content = $this->app->container()->get(ContentServiceInterface::class)->create(
            $siteId ?? $this->siteId,
            [
                'type' => 'post',
                'title' => $title,
                'slug' => strtolower(str_replace(' ', '-', $title)),
                'author_id' => $authorId,
                'status' => 'draft',
                'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
            ],
        );

        return (int) $content->id;
    }

    private function statusOf(int $id): string
    {
        return $this->app->container()->get(ContentServiceInterface::class)
            ->get($id, $this->siteId)->status;
    }

    #[Test]
    public function aContributorSeesOnlyTheirOwnArticles(): void
    {
        $mine = $this->makeUser('writer', 'contributor');
        $theirs = $this->makeUser('other', 'contributor');

        $this->draft('Mine To See', $mine);
        $this->draft('Theirs To Hide', $theirs);

        $this->loginAs($mine);

        $page = $this->body($this->get('/admin/content'));

        self::assertStringContainsString('Mine To See', $page);
        self::assertStringNotContainsString('Theirs To Hide', $page);
    }

    #[Test]
    public function anEditorSeesEveryoneSArticles(): void
    {
        $contributor = $this->makeUser('writer', 'contributor');
        $editor = $this->makeUser('boss', 'editor');

        $this->draft('Written By Someone Else', $contributor);

        $this->loginAs($editor);

        self::assertStringContainsString('Written By Someone Else', $this->body($this->get('/admin/content')));
    }

    #[Test]
    public function aContributorCannotOpenTheReviewScreen(): void
    {
        $contributor = $this->makeUser('writer', 'contributor');
        $id = $this->draft('Not Ready For Me', $contributor);

        $this->loginAs($contributor);

        self::assertSame(403, $this->get('/admin/content/' . $id . '/review')->status());
    }

    #[Test]
    public function aReviewerCanOpenTheReviewScreen(): void
    {
        $contributor = $this->makeUser('writer', 'contributor');
        $reviewer = $this->makeUser('checker', 'reviewer');
        $id = $this->draft('Waiting For A Look', $contributor);

        $this->loginAs($reviewer);

        $response = $this->get('/admin/content/' . $id . '/review');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Decision', $this->body($response));
    }

    #[Test]
    public function aContributorCannotPublishTheirOwnArticle(): void
    {
        $contributor = $this->makeUser('writer', 'contributor');
        $id = $this->draft('Eager To Publish', $contributor);

        $this->loginAs($contributor);

        $response = $this->post('/admin/content/' . $id . '/transition', ['to' => 'published']);

        // The controller redirects with a message rather than a 403 page, because the button
        // is not offered in the first place; the state is what matters.
        self::assertSame(303, $response->status());
        self::assertSame('draft', $this->statusOf($id));
    }

    #[Test]
    public function aContributorCannotSkipStraightToReview(): void
    {
        $contributor = $this->makeUser('writer', 'contributor');
        $id = $this->draft('Skipping Steps', $contributor);

        $this->loginAs($contributor);

        $this->post('/admin/content/' . $id . '/transition', ['to' => 'review']);

        self::assertSame('draft', $this->statusOf($id));
    }

    #[Test]
    public function contentFromAnotherTenantIsA404(): void
    {
        $contributor = $this->makeUser('writer', 'contributor');

        $otherSite = $this->createSiteRow('other', 'other.example');
        $foreignId = $this->draft('Belongs Elsewhere', $contributor, $otherSite);

        $this->loginAs($contributor);

        // A 404 rather than a 403: confirming that another tenant's row exists would leak it.
        self::assertSame(404, $this->get('/admin/content/' . $foreignId)->status());
        self::assertSame(404, $this->get('/admin/content/' . $foreignId . '/review')->status());
        self::assertSame(404, $this->post('/admin/content/' . $foreignId . '/save', ['title' => 'Hijack'])->status());
    }

    #[Test]
    public function anUnknownArticleIsA404NotAServerError(): void
    {
        $this->loginAs($this->makeUser('writer', 'contributor'));

        $response = $this->get('/admin/content/999999');

        self::assertSame(404, $response->status());
        self::assertStringContainsString('Page not found', $this->body($response));
    }

    #[Test]
    public function theReviewQueueAppearsOnlyForSomeoneWhoCanActOnIt(): void
    {
        $contributor = $this->makeUser('writer', 'contributor');
        $reviewer = $this->makeUser('checker', 'reviewer');

        $this->loginAs($contributor);
        self::assertStringNotContainsString('Waiting for review', $this->body($this->get('/admin')));

        $this->loginAs($reviewer);
        self::assertStringContainsString('Waiting for review', $this->body($this->get('/admin')));
    }

    #[Test]
    public function anEditorCanEditAnotherPersonsDraft(): void
    {
        $contributor = $this->makeUser('writer', 'contributor');
        $editor = $this->makeUser('boss', 'editor');
        $id = $this->draft('Needs A Polish', $contributor);

        $this->loginAs($editor);

        $response = $this->post('/admin/content/' . $id . '/save', [
            'title' => 'Polished By The Editor',
            'blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Cleaner body.']],
            ],
        ]);

        self::assertSame(303, $response->status());

        $content = $this->app->container()->get(ContentServiceInterface::class)->get($id, $this->siteId);

        self::assertSame('Polished By The Editor', $content->title);
    }
}
