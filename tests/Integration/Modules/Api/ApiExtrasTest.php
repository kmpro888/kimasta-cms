<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Api;

use CMS\Modules\Seo\Contracts\RedirectServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * The remaining Agent 13 endpoint groups: search, media, SEO, redirects, settings,
 * themes, users and roles.
 *
 * Same harness as `ApiEndpointTest` — the real composition root, so a missing binding
 * or a route registered with the wrong middleware fails here.
 */
final class ApiExtrasTest extends ApiTestCase
{
    private function adminId(): int
    {
        $id = $this->makeUser('admin', 'administrator');
        $this->loginAs($id);

        return $id;
    }

    private function insertMedia(string $name = 'photo.jpg', int $siteId = 0): int
    {
        $siteId = $siteId === 0 ? $this->siteId : $siteId;
        $now = '2026-01-01 00:00:00';

        $this->db->affect(
            'INSERT INTO media (site_id, uploaded_by, original_name, path, mime_type, extension, '
            . 'width, height, filesize, alt_text, caption, created_at, updated_at) '
            . 'VALUES (:site_id, 1, :name, :path, :mime, :ext, 800, 600, 12345, :alt, NULL, :created, :updated)',
            [
                'site_id' => $siteId,
                'name' => $name,
                'path' => '2026/01/' . $name,
                'mime' => 'image/jpeg',
                'ext' => 'jpg',
                'alt' => 'Original alt',
                'created' => $now,
                'updated' => $now,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    // ------------------------------------------------------------------ search

    #[Test]
    public function searchFindsContentByTitle(): void
    {
        $this->publishArticle('A Distinctive Headline');

        $response = $this->call('GET', '/api/v1/search', null, ['q' => 'Distinctive']);

        self::assertSame(200, $response->status());

        $titles = array_column($this->json($response)['data'], 'title');
        self::assertContains('A Distinctive Headline', $titles);
    }

    #[Test]
    public function searchWithoutATermIsA422(): void
    {
        self::assertSame(422, $this->call('GET', '/api/v1/search')->status());
    }

    #[Test]
    public function searchDoesNotReturnDraftsToAnonymousCallers(): void
    {
        $this->app->container()->get(\CMS\Modules\Content\Contracts\ContentServiceInterface::class)
            ->create($this->siteId, [
                'title' => 'Secret Draft Headline',
                'author_id' => 1,
                'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Secret']]],
            ]);

        $titles = array_column(
            $this->json($this->call('GET', '/api/v1/search', null, ['q' => 'Secret']))['data'],
            'title'
        );

        self::assertNotContains('Secret Draft Headline', $titles);
    }

    // ------------------------------------------------------------------- media

    #[Test]
    public function listingMediaRequiresTheReadCapability(): void
    {
        self::assertContains($this->call('GET', '/api/v1/media')->status(), [401, 403]);

        $this->adminId();
        self::assertSame(200, $this->call('GET', '/api/v1/media')->status());
    }

    #[Test]
    public function anImageIsReadableWithItsMetadata(): void
    {
        $this->adminId();
        $id = $this->insertMedia();

        $data = $this->json($this->call('GET', '/api/v1/media/' . $id))['data'];

        self::assertSame('photo.jpg', $data['original_name']);
        self::assertTrue($data['is_image']);
        self::assertSame(800, $data['width']);
        // The absolute storage path must never appear in a response.
        self::assertStringNotContainsString('storage', (string) $data['path']);
    }

    #[Test]
    public function crossSiteMediaIsA404(): void
    {
        $this->adminId();
        $otherSite = $this->createSiteRow('other', 'other.example');
        $foreignId = $this->insertMedia('foreign.jpg', $otherSite);

        self::assertSame(404, $this->call('GET', '/api/v1/media/' . $foreignId)->status());
    }

    #[Test]
    public function mediaMetadataCanBeUpdated(): void
    {
        $this->adminId();
        $id = $this->insertMedia();

        $response = $this->call('PATCH', '/api/v1/media/' . $id, [
            'alt_text' => 'A described image',
            'caption' => 'A caption',
        ]);

        self::assertSame(200, $response->status());

        $data = $this->json($response)['data'];
        self::assertSame('A described image', $data['alt_text']);
        self::assertSame('A caption', $data['caption']);
    }

    #[Test]
    public function updatingMediaWithoutFieldsIsA422(): void
    {
        $this->adminId();
        $id = $this->insertMedia();

        self::assertSame(422, $this->call('PATCH', '/api/v1/media/' . $id, [])->status());
    }

    #[Test]
    public function mediaDeletionRequiresTheDeleteCapability(): void
    {
        $id = $this->insertMedia();

        self::assertContains($this->call('DELETE', '/api/v1/media/' . $id)->status(), [401, 403]);

        $this->adminId();
        self::assertSame(204, $this->call('DELETE', '/api/v1/media/' . $id)->status());
    }

    #[Test]
    public function anImageCanBeUploadedOverMultipart(): void
    {
        $this->adminId();

        $path = $this->path('uploads/upload.png');
        @mkdir(dirname($path), 0775, true);
        imagepng(imagecreatetruecolor(16, 16), $path);

        $response = $this->call('POST', '/api/v1/media', null, [], null, true, [
            'file' => [
                'name' => 'upload.png',
                'type' => 'image/png',
                'tmp_name' => $path,
                'error' => UPLOAD_ERR_OK,
                'size' => (int) filesize($path),
            ],
        ]);

        self::assertSame(201, $response->status(), 'Upload failed: ' . $response->content());

        $data = $this->json($response)['data'];
        self::assertSame('upload.png', $data['original_name']);
        self::assertTrue($data['is_image']);
        self::assertSame(16, $data['width']);

        // The stored path is hashed and never the raw filename, so two uploads of the same
        // name cannot overwrite each other.
        self::assertStringNotContainsString('uploads/upload.png', (string) $data['path']);
    }

    #[Test]
    public function anUploadWithoutAFileIsA422(): void
    {
        $this->adminId();

        self::assertSame(422, $this->call('POST', '/api/v1/media')->status());
    }

    #[Test]
    public function uploadingRequiresTheMediaUploadCapability(): void
    {
        $path = $this->path('uploads/denied.png');
        @mkdir(dirname($path), 0775, true);
        imagepng(imagecreatetruecolor(8, 8), $path);

        $files = ['file' => [
            'name' => 'denied.png',
            'type' => 'image/png',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($path),
        ]];

        self::assertSame(401, $this->call('POST', '/api/v1/media', null, [], null, true, $files)->status());

        $this->loginAs($this->makeUser('norole'));
        self::assertSame(403, $this->call('POST', '/api/v1/media', null, [], null, true, $files)->status());
    }

    #[Test]
    public function anUploadWithoutACsrfTokenIsRejected(): void
    {
        $this->adminId();

        $path = $this->path('uploads/csrf.png');
        @mkdir(dirname($path), 0775, true);
        imagepng(imagecreatetruecolor(8, 8), $path);

        $response = $this->call('POST', '/api/v1/media', null, [], null, false, [
            'file' => [
                'name' => 'csrf.png',
                'type' => 'image/png',
                'tmp_name' => $path,
                'error' => UPLOAD_ERR_OK,
                'size' => (int) filesize($path),
            ],
        ]);

        self::assertSame(403, $response->status());
        self::assertSame('CSRF_TOKEN_MISMATCH', $this->json($response)['error']['code']);
    }
    // --------------------------------------------------------------------- seo

    #[Test]
    public function seoForPublishedContentIsReadableAnonymously(): void
    {
        $contentId = $this->publishArticle('Seo Readable');

        $response = $this->call('GET', '/api/v1/content/' . $contentId . '/seo');

        self::assertSame(200, $response->status());

        $data = $this->json($response)['data'];
        self::assertSame('Seo Readable', $data['title']);
        self::assertStringContainsString('/blog/seo-readable', $data['canonical']);
        self::assertSame('index,follow', $data['robots']);
    }

    #[Test]
    public function seoForADraftIsA404ForAnonymousCallers(): void
    {
        $adminId = $this->adminId();

        $draftId = (int) $this->app->container()->get(\CMS\Modules\Content\Contracts\ContentServiceInterface::class)
            ->create($this->siteId, ['title' => 'Draft Seo', 'author_id' => $adminId])->id;

        $this->logout();

        self::assertSame(404, $this->call('GET', '/api/v1/content/' . $draftId . '/seo')->status());
    }

    #[Test]
    public function seoOverridesAreStored(): void
    {
        $this->adminId();
        $contentId = $this->publishArticle('Seo Writable');

        $response = $this->call('PUT', '/api/v1/content/' . $contentId . '/seo', [
            'meta_title' => 'Custom SEO Title',
            'meta_description' => 'Custom description.',
            'robots_index' => false,
        ]);

        self::assertSame(200, $response->status());

        $data = $this->json($response)['data'];
        self::assertSame('Custom SEO Title', $data['title']);
        self::assertFalse($data['robots_index']);
        self::assertSame('noindex,follow', $data['robots']);
    }

    #[Test]
    public function aRelativeCanonicalIsRejected(): void
    {
        $this->adminId();
        $contentId = $this->publishArticle('Bad Canonical');

        $response = $this->call('PUT', '/api/v1/content/' . $contentId . '/seo', [
            'canonical_url' => '/not-absolute',
        ]);

        self::assertSame(422, $response->status());
        self::assertArrayHasKey('canonical_url', $this->json($response)['error']['details']['fields']);
    }

    #[Test]
    public function writingSeoAnonymouslyIsA401(): void
    {
        $contentId = $this->publishArticle('Anon Seo');

        self::assertSame(401, $this->call('PUT', '/api/v1/content/' . $contentId . '/seo', ['meta_title' => 'x'])->status());
    }

    // ---------------------------------------------------------------- redirects

    #[Test]
    public function redirectsAreListedForTheTenantOnly(): void
    {
        $this->adminId();

        $this->app->container()->get(RedirectServiceInterface::class)
            ->store($this->siteId, '/mine', '/blog/target');

        $otherSite = $this->createSiteRow('other', 'other.example');
        $this->app->container()->get(RedirectServiceInterface::class)
            ->store($otherSite, '/theirs', '/elsewhere');

        $paths = array_column($this->json($this->call('GET', '/api/v1/redirects'))['data'], 'source_path');

        self::assertContains('/mine', $paths);
        self::assertNotContains('/theirs', $paths);
    }

    #[Test]
    public function aRedirectCanBeCreatedAndDeleted(): void
    {
        $this->adminId();

        $created = $this->call('POST', '/api/v1/redirects', [
            'source_path' => '/old-url',
            'target_url' => '/new-url',
            'status_code' => 301,
        ]);

        self::assertSame(201, $created->status());
        self::assertSame('/old-url', $this->json($created)['data']['source_path']);

        $deleted = $this->call('DELETE', '/api/v1/redirects', null, ['source_path' => '/old-url']);
        self::assertSame(204, $deleted->status());
    }

    #[Test]
    public function aRedirectLoopIsA409WithItsOwnCode(): void
    {
        $this->adminId();

        $response = $this->call('POST', '/api/v1/redirects', [
            'source_path' => '/loop',
            'target_url' => '/loop',
        ]);

        self::assertSame(409, $response->status());
        self::assertSame('REDIRECT_LOOP', $this->json($response)['error']['code']);
    }

    #[Test]
    public function anInvalidRedirectTargetIsA422(): void
    {
        $this->adminId();

        $response = $this->call('POST', '/api/v1/redirects', [
            'source_path' => '/bad',
            'target_url' => 'javascript:alert(1)',
        ]);

        self::assertSame(422, $response->status());
    }

    #[Test]
    public function creatingARedirectAnonymouslyIsA401(): void
    {
        $response = $this->call('POST', '/api/v1/redirects', [
            'source_path' => '/x',
            'target_url' => '/y',
        ]);

        self::assertSame(401, $response->status());
    }

    // ----------------------------------------------------------------- settings

    #[Test]
    public function settingsAreReadableWithTheCapability(): void
    {
        self::assertContains($this->call('GET', '/api/v1/settings')->status(), [401, 403]);

        $this->adminId();
        $response = $this->call('GET', '/api/v1/settings');

        self::assertSame(200, $response->status());

        $data = $this->json($response)['data'];
        self::assertSame('general', $data['namespace']);
        self::assertArrayHasKey('site_title', $data['values']);
    }

    #[Test]
    public function aSettingCanBeUpdated(): void
    {
        $this->adminId();

        $response = $this->call('PUT', '/api/v1/settings', [
            'namespace' => 'general',
            'values' => ['site_title' => 'Renamed Site'],
        ]);

        self::assertSame(200, $response->status());
        self::assertSame('Renamed Site', $this->json($response)['data']['values']['site_title']);
    }

    #[Test]
    public function aKeyOutsideTheAllowListIsRejected(): void
    {
        $this->adminId();

        $response = $this->call('PUT', '/api/v1/settings', [
            'namespace' => 'general',
            'values' => ['some_junk_key' => 'x'],
        ]);

        self::assertSame(422, $response->status());
    }

    #[Test]
    public function anUnknownNamespaceIsRejected(): void
    {
        $this->adminId();

        self::assertSame(422, $this->call('GET', '/api/v1/settings', null, ['namespace' => 'nope'])->status());
    }

    // ------------------------------------------------------------------- themes

    #[Test]
    public function installedThemesAreListedAndOneIsActive(): void
    {
        $this->adminId();

        $response = $this->call('GET', '/api/v1/themes');

        self::assertSame(200, $response->status());

        $themes = $this->json($response)['data'];
        self::assertNotEmpty($themes);
        self::assertSame('default', $themes[0]['name']);
        self::assertTrue($themes[0]['active']);
    }

    #[Test]
    public function theActiveThemeIsReadable(): void
    {
        $this->adminId();

        $data = $this->json($this->call('GET', '/api/v1/themes/active'))['data'];

        self::assertSame('default', $data['name']);
    }

    #[Test]
    public function activatingAnInstalledThemeSucceeds(): void
    {
        $this->adminId();

        $response = $this->call('POST', '/api/v1/themes/default/activate');

        self::assertSame(200, $response->status());
        self::assertTrue($this->json($response)['data']['active']);
    }

    #[Test]
    public function activatingAnUnknownThemeIsA404(): void
    {
        $this->adminId();

        self::assertSame(404, $this->call('POST', '/api/v1/themes/nonexistent/activate')->status());
    }

    #[Test]
    public function activatingAThemeRequiresTheManageCapability(): void
    {
        // A user with no role holds no capabilities.
        $this->loginAs($this->makeUser('norole'));

        self::assertSame(403, $this->call('POST', '/api/v1/themes/default/activate')->status());
    }

    // -------------------------------------------------------------------- users

    #[Test]
    public function listingUsersRequiresTheCapability(): void
    {
        self::assertContains($this->call('GET', '/api/v1/users')->status(), [401, 403]);

        $this->adminId();
        self::assertSame(200, $this->call('GET', '/api/v1/users')->status());
    }

    #[Test]
    public function aUserIsCreatedWithoutLeakingThePasswordHash(): void
    {
        $this->adminId();

        $response = $this->call('POST', '/api/v1/users', [
            'username' => 'neweditor',
            'email' => 'neweditor@example.com',
            'password' => 'a-strong-password',
            'display_name' => 'New Editor',
        ]);

        self::assertSame(201, $response->status());

        self::assertStringNotContainsString('$2y$', $response->content());
        self::assertStringNotContainsString('password', $response->content());
    }

    #[Test]
    public function creatingAUserAnonymouslyIsA401(): void
    {
        $response = $this->call('POST', '/api/v1/users', [
            'username' => 'x',
            'email' => 'x@example.com',
            'password' => 'y',
        ]);

        self::assertSame(401, $response->status());
    }

    #[Test]
    public function creatingADuplicateUserIsA409(): void
    {
        $this->adminId();
        $this->makeUser('duplicate');

        $response = $this->call('POST', '/api/v1/users', [
            'username' => 'duplicate',
            'email' => 'duplicate@example.com',
            'password' => 'another-password',
        ]);

        self::assertSame(409, $response->status());
        self::assertSame('RESOURCE_CONFLICT', $this->json($response)['error']['code']);
    }

    #[Test]
    public function anInvalidEmailIsA422(): void
    {
        $this->adminId();

        $response = $this->call('POST', '/api/v1/users', [
            'username' => 'badmail',
            'email' => 'not-an-email',
            'password' => 'a-strong-password',
        ]);

        self::assertSame(422, $response->status());
        self::assertArrayHasKey('email', $this->json($response)['error']['details']['fields']);
    }

    #[Test]
    public function aUserCanReadTheirOwnRecord(): void
    {
        $id = $this->makeUser('selfreader');
        $this->loginAs($id);

        // No `users.read` capability, but reading yourself is always allowed.
        $response = $this->call('GET', '/api/v1/users/' . $id);

        self::assertSame(200, $response->status());
        self::assertSame('selfreader', $this->json($response)['data']['username']);
    }

    #[Test]
    public function aUserCannotReadAnotherUsersRecordWithoutTheCapability(): void
    {
        $other = $this->makeUser('otheruser');
        $this->loginAs($this->makeUser('selfreader'));

        self::assertSame(403, $this->call('GET', '/api/v1/users/' . $other)->status());
    }

    #[Test]
    public function crossSiteUsersAreA404(): void
    {
        $this->adminId();
        $otherSite = $this->createSiteRow('other', 'other.example');

        $foreignUser = (int) $this->app->container()
            ->get(\CMS\Modules\Identity\Contracts\UserServiceInterface::class)
            ->create($otherSite, [
                'username' => 'foreigner',
                'email' => 'foreigner@example.com',
                'password' => 'a-password',
                'display_name' => 'Foreigner',
            ])->id;

        self::assertSame(404, $this->call('GET', '/api/v1/users/' . $foreignUser)->status());
    }

    #[Test]
    public function aUserCannotDeleteThemselves(): void
    {
        $id = $this->adminId();

        $response = $this->call('DELETE', '/api/v1/users/' . $id);

        self::assertSame(409, $response->status());
        self::assertSame('INVALID_STATE_TRANSITION', $this->json($response)['error']['code']);
    }

    #[Test]
    public function rolesCanBeReadAndReplaced(): void
    {
        $admin = $this->adminId();
        $target = $this->makeUser('target');

        self::assertSame(200, $this->call('GET', '/api/v1/users/' . $target . '/roles')->status());

        $response = $this->call('PUT', '/api/v1/users/' . $target . '/roles', [
            'roles' => ['editor'],
        ]);

        self::assertSame(200, $response->status());
        self::assertSame(['editor'], $this->json($response)['data']);

        // Replacing again removes the previous role.
        $this->call('PUT', '/api/v1/users/' . $target . '/roles', ['roles' => ['author']]);
        self::assertSame(['author'], $this->json($this->call('GET', '/api/v1/users/' . $target . '/roles'))['data']);

        self::assertNotSame($admin, $target);
    }

    #[Test]
    public function anUnknownRoleIsRejected(): void
    {
        $this->adminId();
        $target = $this->makeUser('target');

        $response = $this->call('PUT', '/api/v1/users/' . $target . '/roles', ['roles' => ['wizard']]);

        self::assertSame(422, $response->status());
        self::assertArrayHasKey('roles', $this->json($response)['error']['details']['fields']);
    }

    // ----------------------------------------------------------------- comments

    #[Test]
    public function anAnonymousReaderSeesOnlyApprovedComments(): void
    {
        $contentId = $this->publishArticle('Threaded Article');
        $userId = $this->makeUser('reader');

        $comments = $this->app->container()->get(\CMS\Modules\Comment\Contracts\CommentServiceInterface::class);

        $approved = $comments->submit($this->siteId, $contentId, $userId, 'Visible remark.');
        $comments->approve((int) $approved->id, $this->siteId);
        $comments->submit($this->siteId, $contentId, $userId, 'Hidden remark.');

        $data = $this->json($this->call('GET', '/api/v1/content/' . $contentId . '/comments'))['data'];

        self::assertCount(1, $data);
        self::assertSame('Visible remark.', $data[0]['body']);
    }

    #[Test]
    public function submittingACommentAnonymouslyIsA401(): void
    {
        $contentId = $this->publishArticle('Anon Comment');

        $response = $this->call('POST', '/api/v1/content/' . $contentId . '/comments', ['body' => 'Nope.']);

        self::assertSame(401, $response->status());
    }

    #[Test]
    public function anAuthenticatedUserCanSubmitAComment(): void
    {
        $contentId = $this->publishArticle('Open Article');
        $this->loginAs($this->makeUser('commenter'));

        $response = $this->call('POST', '/api/v1/content/' . $contentId . '/comments', ['body' => 'My two cents.']);

        self::assertSame(201, $response->status());

        $data = $this->json($response)['data'];
        self::assertSame('pending', $data['status']);
        self::assertSame(0, $data['depth']);
    }

    #[Test]
    public function aTooShortCommentIsA422(): void
    {
        $contentId = $this->publishArticle('Short Comment');
        $this->loginAs($this->makeUser('commenter'));

        $response = $this->call('POST', '/api/v1/content/' . $contentId . '/comments', ['body' => 'x']);

        self::assertSame(422, $response->status());
        self::assertArrayHasKey('body', $this->json($response)['error']['details']['fields']);
    }

    #[Test]
    public function commentingOnAClosedArticleIsA409(): void
    {
        $contentId = $this->publishArticle('Closed Article');
        $this->app->container()->get(\CMS\Modules\Content\Contracts\ContentServiceInterface::class)
            ->update($contentId, $this->siteId, ['comment_status' => 'closed']);

        $this->loginAs($this->makeUser('commenter'));

        $response = $this->call('POST', '/api/v1/content/' . $contentId . '/comments', ['body' => 'Too late.']);

        self::assertSame(409, $response->status());
        self::assertSame('COMMENTS_CLOSED', $this->json($response)['error']['code']);
    }

    #[Test]
    public function moderatingRequiresTheCapability(): void
    {
        $contentId = $this->publishArticle('Moderation Target');
        $userId = $this->makeUser('reader');

        $comment = $this->app->container()->get(\CMS\Modules\Comment\Contracts\CommentServiceInterface::class)
            ->submit($this->siteId, $contentId, $userId, 'Awaiting review.');

        $this->loginAs($this->makeUser('norole'));

        self::assertSame(403, $this->call('POST', '/api/v1/comments/' . $comment->id . '/approve')->status());
    }

    #[Test]
    public function aModeratorCanApproveAComment(): void
    {
        $contentId = $this->publishArticle('Approve Target');
        $userId = $this->makeUser('reader');

        $comment = $this->app->container()->get(\CMS\Modules\Comment\Contracts\CommentServiceInterface::class)
            ->submit($this->siteId, $contentId, $userId, 'Please approve.');

        $this->adminId();

        $response = $this->call('POST', '/api/v1/comments/' . $comment->id . '/approve');

        self::assertSame(200, $response->status());
        self::assertSame('approved', $this->json($response)['data']['status']);

        // And it now shows up in the public thread.
        $this->logout();
        $data = $this->json($this->call('GET', '/api/v1/content/' . $contentId . '/comments'))['data'];
        self::assertCount(1, $data);
    }

    #[Test]
    public function theModerationQueueRequiresTheCapability(): void
    {
        self::assertContains($this->call('GET', '/api/v1/comments')->status(), [401, 403]);

        $this->adminId();
        self::assertSame(200, $this->call('GET', '/api/v1/comments')->status());
    }

    #[Test]
    public function deletingACommentCascadesToReplies(): void
    {
        $contentId = $this->publishArticle('Cascade Target');
        $userId = $this->makeUser('reader');
        $comments = $this->app->container()->get(\CMS\Modules\Comment\Contracts\CommentServiceInterface::class);

        $parent = $comments->submit($this->siteId, $contentId, $userId, 'Parent remark.');
        $comments->approve((int) $parent->id, $this->siteId);
        $reply = $comments->submit($this->siteId, $contentId, $userId, 'Reply remark.', (int) $parent->id);
        $comments->approve((int) $reply->id, $this->siteId);

        $this->adminId();
        self::assertSame(204, $this->call('DELETE', '/api/v1/comments/' . $parent->id)->status());

        self::assertSame(0, $comments->countForSite($this->siteId));
    }

    #[Test]
    public function aCrossSiteCommentIdIsA404(): void
    {
        // The comment must belong to the OTHER tenant, while the request still resolves
        // the first one.
        $otherSite = $this->createSiteRow('other', 'other.example');

        $foreignContentId = (int) $this->app->container()
            ->get(\CMS\Modules\Content\Contracts\ContentServiceInterface::class)
            ->create($otherSite, [
                'title' => 'Foreign Article',
                'author_id' => 1,
                'status' => 'published',
                'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body']]],
            ])->id;

        $foreignUserId = (int) $this->app->container()
            ->get(\CMS\Modules\Identity\Contracts\UserServiceInterface::class)
            ->create($otherSite, [
                'username' => 'foreigner',
                'email' => 'foreigner@example.com',
                'password' => 'a-password',
                'display_name' => 'Foreigner',
            ])->id;

        $foreignComment = $this->app->container()
            ->get(\CMS\Modules\Comment\Contracts\CommentServiceInterface::class)
            ->submit($otherSite, $foreignContentId, $foreignUserId, 'Other tenant remark.');

        $this->adminId();

        self::assertSame(404, $this->call('POST', '/api/v1/comments/' . $foreignComment->id . '/approve')->status());
        self::assertSame(404, $this->call('DELETE', '/api/v1/comments/' . $foreignComment->id)->status());
    }
}
