<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * Scheduling an approved article from the editor.
 *
 * The date a person types carries no timezone, so it is read in the site's zone. Reading it in
 * UTC would publish at the wrong hour for every site that is not on UTC, and nobody would
 * notice until an article appeared early or late.
 */
#[CoversClass(\CMS\Modules\Admin\Http\Controller\ContentController::class)]
final class AdminScheduleTest extends AdminTestCase
{
    private function approvedArticle(string $title, int $authorId): int
    {
        $contents = $this->app->container()->get(ContentServiceInterface::class);

        $created = $contents->create($this->siteId, [
            'type' => 'post',
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'author_id' => $authorId,
            'status' => 'draft',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);

        // Straight to approved for setup: the editorial path is covered by AdminContentFlowTest.
        $contents->setStatus((int) $created->id, $this->siteId, 'approved');

        return (int) $created->id;
    }

    private function content(int $id): \CMS\Modules\Content\Entity\Content
    {
        return $this->app->container()->get(ContentServiceInterface::class)->get($id, $this->siteId);
    }

    #[Test]
    public function anEditorCanScheduleAnApprovedArticle(): void
    {
        $author = $this->makeUser('writer', 'contributor');
        $id = $this->approvedArticle('Goes Live Later', $author);

        $this->loginAs($this->makeUser('boss', 'editor'));

        $response = $this->post('/admin/content/' . $id . '/schedule', [
            'publish_at' => '2099-12-01T09:30',
        ]);

        self::assertSame(303, $response->status());

        $content = $this->content($id);

        self::assertSame('scheduled', $content->status);
        self::assertNotNull($content->scheduledAt);
        self::assertSame('2099-12-01 09:30:00', $content->scheduledAt->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function aPastTimeIsRefusedAndNothingChanges(): void
    {
        $author = $this->makeUser('writer', 'contributor');
        $id = $this->approvedArticle('Should Not Schedule', $author);

        $this->loginAs($this->makeUser('boss', 'editor'));

        $response = $this->post('/admin/content/' . $id . '/schedule', [
            'publish_at' => '2000-01-01T00:00',
        ]);

        self::assertSame(303, $response->status());
        self::assertSame('approved', $this->content($id)->status);
        self::assertNull($this->content($id)->scheduledAt);
    }

    #[Test]
    public function aMalformedDateIsRefused(): void
    {
        $author = $this->makeUser('writer', 'contributor');
        $id = $this->approvedArticle('Bad Date', $author);

        $this->loginAs($this->makeUser('boss', 'editor'));

        foreach (['tomorrow', '', '2099-13-45T99:99'] as $value) {
            $this->post('/admin/content/' . $id . '/schedule', ['publish_at' => $value]);

            self::assertSame('approved', $this->content($id)->status, 'Value "' . $value . '" must not schedule.');
        }
    }

    #[Test]
    public function aContributorCannotSchedule(): void
    {
        $author = $this->makeUser('writer', 'contributor');
        $id = $this->approvedArticle('Not Mine To Schedule', $author);

        $this->loginAs($author);

        $this->post('/admin/content/' . $id . '/schedule', ['publish_at' => '2099-12-01T09:30']);

        self::assertSame('approved', $this->content($id)->status);
        self::assertNull($this->content($id)->scheduledAt);
    }

    #[Test]
    public function anArticleThatIsNotApprovedCannotBeScheduled(): void
    {
        $author = $this->makeUser('writer', 'contributor');

        $draft = $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => 'Still A Draft',
            'slug' => 'still-a-draft',
            'author_id' => $author,
            'status' => 'draft',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);

        $this->loginAs($this->makeUser('boss', 'editor'));

        $this->post('/admin/content/' . (int) $draft->id . '/schedule', [
            'publish_at' => '2099-12-01T09:30',
        ]);

        self::assertSame('draft', $this->content((int) $draft->id)->status);
    }

    #[Test]
    public function anAlreadyScheduledArticleCanBeRescheduled(): void
    {
        $author = $this->makeUser('writer', 'contributor');
        $id = $this->approvedArticle('Moves Its Date', $author);

        $this->loginAs($this->makeUser('boss', 'editor'));

        $this->post('/admin/content/' . $id . '/schedule', ['publish_at' => '2099-12-01T09:30']);
        self::assertSame('2099-12-01 09:30:00', $this->content($id)->scheduledAt->format('Y-m-d H:i:s'));

        $this->post('/admin/content/' . $id . '/schedule', ['publish_at' => '2099-12-02T11:00']);

        self::assertSame('scheduled', $this->content($id)->status);
        self::assertSame('2099-12-02 11:00:00', $this->content($id)->scheduledAt->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function theSchedulePanelAppearsOnlyForSomeoneWhoCanUseIt(): void
    {
        $author = $this->makeUser('writer', 'contributor');
        $id = $this->approvedArticle('Panel Visibility', $author);

        $this->loginAs($author);
        self::assertStringNotContainsString('name="publish_at"', $this->body($this->get('/admin/content/' . $id)));

        $this->loginAs($this->makeUser('boss', 'editor'));
        $html = $this->body($this->get('/admin/content/' . $id));

        self::assertStringContainsString('name="publish_at"', $html);
        self::assertStringContainsString('type="datetime-local"', $html);
    }

    #[Test]
    public function theCurrentScheduleIsShownInThePanel(): void
    {
        $author = $this->makeUser('writer', 'contributor');
        $id = $this->approvedArticle('Shows Its Date', $author);

        $this->loginAs($this->makeUser('boss', 'editor'));
        $this->post('/admin/content/' . $id . '/schedule', ['publish_at' => '2099-12-01T09:30']);

        $html = $this->body($this->get('/admin/content/' . $id));

        self::assertStringContainsString('Reschedule', $html);
        self::assertStringContainsString('Scheduled for', $html);
        self::assertStringContainsString('2099-12-01T09:30', $html, 'The field is pre-filled with the current time.');
    }
}
