<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Notification\Contracts\NotificationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * The notification screens.
 *
 * Two scopes behind one menu entry: the list is the signed-in person's own, the switches are the
 * site's. That is why reading needs no capability and changing the switches needs
 * `settings.update`.
 */
#[CoversClass(\CMS\Modules\Admin\Http\Controller\NotificationController::class)]
final class AdminNotificationTest extends AdminTestCase
{
    private ?int $authorId = null;

    private function author(): int
    {
        return $this->authorId ??= $this->makeUser('writer', 'contributor');
    }

    private function article(string $title): int
    {
        return (int) $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'author_id' => $this->author(),
            'status' => 'draft',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ])->id;
    }

    /**
     * Fire an editorial event, which is what the workflow does in production.
     */
    private function fire(string $event, int $contentId): int
    {
        return $this->app->container()->get(NotificationServiceInterface::class)
            ->handleEvent($event, ['site_id' => $this->siteId, 'id' => $contentId]);
    }

    private function notifications(): NotificationServiceInterface
    {
        return $this->app->container()->get(NotificationServiceInterface::class);
    }

    #[Test]
    public function theListRequiresSignIn(): void
    {
        self::assertSame(303, $this->get('/admin/notifications')->status());
    }

    #[Test]
    public function aPersonSeesTheNotificationsAddressedToThem(): void
    {
        $contentId = $this->article('Revision Coming');
        $this->fire('content.revision_requested', $contentId);

        $this->loginAs($this->author());

        $response = $this->get('/admin/notifications');
        $html = $this->body($response);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Revision requested', $html);
        self::assertStringContainsString('Revision Coming', $html);
        self::assertStringContainsString('1 unread', $html);
    }

    #[Test]
    public function aNotificationAddressedToSomeoneElseIsNotListed(): void
    {
        $reviewer = $this->makeUser('checker', 'reviewer');
        $contentId = $this->article('Submitted For Review');

        // `content.submitted` notifies reviewers, never the author.
        $this->fire('content.submitted', $contentId);

        $this->loginAs($this->author());
        self::assertStringNotContainsString('Content submitted for review', $this->body($this->get('/admin/notifications')));

        $this->loginAs($reviewer);
        self::assertStringContainsString('Content submitted for review', $this->body($this->get('/admin/notifications')));
    }

    #[Test]
    public function markingOneReadLeavesTheOthersAlone(): void
    {
        $contentId = $this->article('Two Notices');
        $this->fire('content.revision_requested', $contentId);
        $this->fire('content.approved', $contentId);

        $author = $this->author();
        $this->loginAs($author);

        $items = $this->notifications()->listForUser($this->siteId, $author, true);
        self::assertCount(2, $items);

        $response = $this->post('/admin/notifications/' . $items[0]->id . '/read');

        self::assertSame(303, $response->status());
        self::assertSame(1, $this->notifications()->unreadCount($this->siteId, $author));
    }

    #[Test]
    public function markingAllReadClearsTheCount(): void
    {
        $contentId = $this->article('Many Notices');
        $this->fire('content.revision_requested', $contentId);
        $this->fire('content.approved', $contentId);

        $author = $this->author();
        $this->loginAs($author);

        $response = $this->post('/admin/notifications/read-all');

        self::assertSame(303, $response->status());
        self::assertSame(0, $this->notifications()->unreadCount($this->siteId, $author));
    }

    #[Test]
    public function anotherPersonsNotificationCannotBeMarkedRead(): void
    {
        $reviewer = $this->makeUser('checker', 'reviewer');
        $contentId = $this->article('Someone Elses Notice');
        $this->fire('content.submitted', $contentId);

        $theirs = $this->notifications()->listForUser($this->siteId, $reviewer, false)[0] ?? null;
        self::assertNotNull($theirs);

        $this->loginAs($this->author());

        // A 404, not a 403: confirming it exists would leak another person's inbox.
        self::assertSame(404, $this->post('/admin/notifications/' . $theirs->id . '/read')->status());
        self::assertSame(1, $this->notifications()->unreadCount($this->siteId, $reviewer));
    }

    #[Test]
    public function theUnreadFilterHidesReadItems(): void
    {
        $contentId = $this->article('Filtered Notice');
        $this->fire('content.revision_requested', $contentId);

        $author = $this->author();
        $this->loginAs($author);

        $items = $this->notifications()->listForUser($this->siteId, $author, false);
        $this->post('/admin/notifications/' . $items[0]->id . '/read');

        $unreadHtml = $this->body($this->get('/admin/notifications', ['filter' => 'unread']));
        self::assertStringContainsString('Nothing unread', $unreadHtml);

        $allHtml = $this->body($this->get('/admin/notifications'));
        self::assertStringContainsString('Revision requested', $allHtml);
    }

    #[Test]
    public function theSwitchesRequireTheSettingsCapability(): void
    {
        $this->loginAs($this->author());

        self::assertSame(403, $this->get('/admin/notifications/preferences')->status());
        self::assertSame(403, $this->post('/admin/notifications/preferences')->status());

        // An editor can moderate comments and publish, but the site's email switches are an
        // administrator decision: settings.update, which the editor does not hold.
        $this->loginAs($this->makeUser('boss', 'editor'));
        self::assertSame(403, $this->get('/admin/notifications/preferences')->status());
    }

    #[Test]
    public function anAdministratorSeesTheSwitchMatrix(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $response = $this->get('/admin/notifications/preferences');
        $html = $this->body($response);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Submitted for review', $html);
        self::assertStringContainsString('prefs[content.submitted][email]', $html);
        self::assertStringContainsString('In-app', $html);
        self::assertStringContainsString('Email', $html);
    }

    #[Test]
    public function savingTurnOnEmailAndOffInAppIsPersisted(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $service = $this->notifications();

        self::assertFalse($service->isEnabled($this->siteId, 'content.published', 'email'), 'Email starts off.');
        self::assertTrue($service->isEnabled($this->siteId, 'content.published', 'internal'), 'In-app starts on.');

        // Only the email box for `published` is ticked, so every other switch must end up off.
        $response = $this->post('/admin/notifications/preferences', [
            'prefs' => [
                'content.published' => ['email' => '1'],
            ],
        ]);

        self::assertSame(303, $response->status());
        self::assertTrue($service->isEnabled($this->siteId, 'content.published', 'email'));
        self::assertFalse(
            $service->isEnabled($this->siteId, 'content.published', 'internal'),
            'An unchecked box means off, and must actually be written.'
        );
    }

    #[Test]
    public function theSwitchStateSurvivesAReload(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $this->post('/admin/notifications/preferences', [
            'prefs' => [
                'content.approved' => ['internal' => '1', 'email' => '1'],
            ],
        ]);

        $html = $this->body($this->get('/admin/notifications/preferences'));

        self::assertMatchesRegularExpression(
            '/name="prefs\[content\.approved\]\[email\]"\s+value="1"\s+checked/',
            $html
        );
    }
}
