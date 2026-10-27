<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Identity\Contracts\PasswordResetServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Modules\Revision\Contracts\RevisionServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * Two services that had no way in: revision restore, and issuing a password reset.
 *
 * A revision history nobody can roll back is an audit log at best, and an administrator whose user
 * forgot a password had no way to help them — the public site has no "forgot password" page.
 */
#[CoversClass(\CMS\Modules\Admin\Http\Controller\RevisionController::class)]
#[CoversClass(\CMS\Modules\Admin\Http\Controller\PasswordResetAdminController::class)]
final class AdminRevisionAndResetTest extends AdminTestCase
{
    private ?int $writerId = null;

    private function author(): int
    {
        return $this->writerId ??= $this->makeUser('writer', 'contributor');
    }

    private function revisions(): RevisionServiceInterface
    {
        return $this->app->container()->get(RevisionServiceInterface::class);
    }

    private function article(string $slug = 'history-article', string $title = 'Original Title'): int
    {
        $content = $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => $title,
            'slug' => $slug,
            'author_id' => $this->author(),
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);

        return (int) $content->id;
    }

    #[Test]
    public function theHistoryIsListedForSomeoneWhoMayEditContent(): void
    {
        $id = $this->article();
        $this->revisions()->snapshot($id, $this->siteId, $this->makeUser('ed', 'editor'));

        $this->loginAs($this->makeUser('boss', 'administrator'));

        $response = $this->get('/admin/content/' . $id . '/revisions');
        $html = $this->body($response);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Original Title', $html);
        self::assertStringContainsString('Restore', $html);
    }

    #[Test]
    public function aContributorCannotSeeTheHistory(): void
    {
        $id = $this->article();
        $this->loginAs($this->makeUser('w2', 'contributor'));

        self::assertSame(403, $this->get('/admin/content/' . $id . '/revisions')->status());
    }

    #[Test]
    public function restoringPutsTheOldVersionBackAndKeepsWhatItReplaced(): void
    {
        $editor = $this->makeUser('ed', 'editor');
        $id = $this->article('history-article', 'First Title');

        $revisionId = (int) $this->revisions()->snapshot($id, $this->siteId, $editor)->id;

        $this->app->container()->get(ContentServiceInterface::class)
            ->update($id, $this->siteId, ['title' => 'Second Title']);

        $this->loginAs($this->makeUser('boss', 'administrator'));

        $response = $this->post('/admin/content/' . $id . '/revisions/restore', ['revision_id' => $revisionId]);

        self::assertSame(303, $response->status());

        $now = $this->app->container()->get(ContentServiceInterface::class)->get($id, $this->siteId);
        self::assertSame('First Title', $now->title, 'The old version is back.');

        // The replaced version was snapshotted, so the restore is itself undoable.
        $titles = array_map(
            static fn ($revision): string => (string) $revision->title,
            $this->revisions()->listFor($id, $this->siteId)
        );

        self::assertContains('Second Title', $titles);
    }

    #[Test]
    public function aRevisionFromAnotherArticleIsA404(): void
    {
        $mine = $this->article('mine', 'Mine');
        $other = $this->article('other', 'Other');

        $foreignRevision = (int) $this->revisions()->snapshot($other, $this->siteId, $this->makeUser('ed', 'editor'))->id;

        $this->loginAs($this->makeUser('boss', 'administrator'));

        self::assertSame(
            404,
            $this->post('/admin/content/' . $mine . '/revisions/restore', ['revision_id' => $foreignRevision])->status()
        );
    }

    #[Test]
    public function anAdministratorCanIssueAWorkingResetLink(): void
    {
        $target = $this->makeUser('forgetful', 'contributor');
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $response = $this->post('/admin/users/' . $target . '/reset-password');

        self::assertSame(303, $response->status());

        // The link is shown once, in the flash that follows the redirect.
        $page = $this->body($this->get('/admin/users/' . $target));
        self::assertMatchesRegularExpression('#/reset-password\?token=([A-Za-z0-9]+)#', $page, 'The flash carries the link.');

        preg_match('#/reset-password\?token=([A-Za-z0-9]+)#', $page, $matches);

        // And the token actually works, through the service the public page would use.
        $changed = $this->app->container()->get(PasswordResetServiceInterface::class)
            ->consume($matches[1], 'a-brand-new-password', $this->siteId);

        self::assertSame($target, $changed);

        $user = $this->app->container()->get(UserServiceInterface::class)->find($target, $this->siteId);
        self::assertNotNull($user);
        self::assertTrue(password_verify('a-brand-new-password', $user->passwordHash));
    }

    #[Test]
    public function anUnknownUserIsA404(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        self::assertSame(404, $this->post('/admin/users/999999/reset-password')->status());
    }

    #[Test]
    public function aContributorCannotIssueAReset(): void
    {
        $target = $this->makeUser('someone', 'contributor');
        $this->loginAs($this->makeUser('writer', 'contributor'));

        self::assertSame(403, $this->post('/admin/users/' . $target . '/reset-password')->status());
    }
}
