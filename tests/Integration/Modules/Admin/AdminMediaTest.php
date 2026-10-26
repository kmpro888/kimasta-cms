<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use CMS\Core\Http\Request;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Media\Entity\Media;
use CMS\Modules\Media\Entity\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * The media library and the picker built on top of it.
 *
 * The picker has no JavaScript: choosing an image is a form post that names its target. These
 * tests exercise that path the way a browser would, including the parts that are easy to get
 * wrong -- a guessed id from another tenant, an image still referenced, and the blank trailing
 * block row that means "add" rather than "replace".
 */
#[CoversClass(\CMS\Modules\Admin\Http\Controller\MediaController::class)]
#[CoversClass(\CMS\Modules\Admin\Http\Controller\ContentController::class)]
final class AdminMediaTest extends AdminTestCase
{
    /**
     * Write a real PNG and store it through the service, so the row has real dimensions.
     */
    private function uploadImage(string $name = 'picture', int $width = 40, int $height = 30): Media
    {
        $path = $this->path('uploads/' . $name . '.png');
        @mkdir(dirname($path), 0775, true);

        $image = imagecreatetruecolor($width, $height);
        imagepng($image, $path);

        return $this->app->container()->get(MediaServiceInterface::class)->upload(
            $this->siteId,
            $this->makeUser('uploader', 'editor'),
            new UploadedFile(
                tmpPath: $path,
                originalName: $name . '.png',
                size: (int) filesize($path),
                clientMime: 'image/png',
                error: UPLOAD_ERR_OK,
            ),
            'An ' . $name,
        );
    }

    private function draft(string $title, ?int $authorId = null): int
    {
        $content = $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'author_id' => $authorId ?? $this->makeUser('writer', 'contributor'),
            'status' => 'draft',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);

        return (int) $content->id;
    }

    private function content(int $id): \CMS\Modules\Content\Entity\Content
    {
        return $this->app->container()->get(ContentServiceInterface::class)->get($id, $this->siteId);
    }

    // ---------------------------------------------------------------- library

    #[Test]
    public function theLibraryRequiresSignIn(): void
    {
        self::assertSame(303, $this->get('/admin/media')->status());
    }

    #[Test]
    public function theLibraryListsStoredImages(): void
    {
        $this->uploadImage('harbour');

        $this->loginAs($this->makeUser('reader', 'editor'));

        $html = $this->body($this->get('/admin/media'));

        self::assertStringContainsString('harbour.png', $html);
        self::assertStringContainsString('type="file"', $html);
        self::assertStringContainsString('40x30', $html);
    }

    #[Test]
    public function aFileCanBeUploadedThroughTheForm(): void
    {
        $this->loginAs($this->makeUser('uploader', 'contributor'));

        $path = $this->path('uploads/box.png');
        @mkdir(dirname($path), 0775, true);
        imagepng(imagecreatetruecolor(20, 10), $path);

        $response = $this->post('/admin/media', ['alt_text' => 'A box'], true, [
            'file' => [
                'name' => 'box.png',
                'type' => 'image/png',
                'tmp_name' => $path,
                'error' => UPLOAD_ERR_OK,
                'size' => (int) filesize($path),
            ],
        ]);

        self::assertSame(303, $response->status());

        $stored = $this->app->container()->get(MediaServiceInterface::class)->listForSite($this->siteId);

        self::assertCount(1, $stored);
        self::assertSame('box.png', $stored[0]->originalName);
        self::assertSame('A box', $stored[0]->altText);
    }

    #[Test]
    public function anUploadWithoutAFileExplainsItself(): void
    {
        $this->loginAs($this->makeUser('uploader', 'contributor'));

        $response = $this->post('/admin/media', ['alt_text' => 'nothing attached']);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Choose a file', $this->body($response));
    }

    #[Test]
    public function anUnsupportedFileTypeIsRefused(): void
    {
        $this->loginAs($this->makeUser('uploader', 'contributor'));

        $path = $this->path('uploads/notes.txt');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, 'this is not an image');

        $response = $this->post('/admin/media', [], true, [
            'file' => [
                'name' => 'notes.txt',
                'type' => 'text/plain',
                'tmp_name' => $path,
                'error' => UPLOAD_ERR_OK,
                'size' => (int) filesize($path),
            ],
        ]);

        self::assertSame(422, $response->status());
        self::assertCount(0, $this->app->container()->get(MediaServiceInterface::class)->listForSite($this->siteId));
    }

    #[Test]
    public function aContributorMayUploadButNotDelete(): void
    {
        $media = $this->uploadImage('kept');
        $this->loginAs($this->makeUser('writer', 'contributor'));

        // The catalogue grants media.read and media.upload to a contributor, not delete.
        self::assertSame(200, $this->get('/admin/media')->status());
        self::assertSame(403, $this->post('/admin/media/' . $media->id . '/delete')->status());

        self::assertCount(1, $this->app->container()->get(MediaServiceInterface::class)->listForSite($this->siteId));
    }

    #[Test]
    public function anEditorCanDeleteAnUnusedImage(): void
    {
        $media = $this->uploadImage('disposable');
        $this->loginAs($this->makeUser('boss', 'editor'));

        $response = $this->post('/admin/media/' . $media->id . '/delete');

        self::assertSame(303, $response->status());
        self::assertCount(0, $this->app->container()->get(MediaServiceInterface::class)->listForSite($this->siteId));
    }

    #[Test]
    public function anImageStillUsedByAnArticleIsNotDeleted(): void
    {
        $media = $this->uploadImage('in-use');
        $id = $this->draft('Uses The Image');

        $this->loginAs($this->makeUser('boss', 'editor'));
        $this->post('/admin/content/' . $id . '/featured', ['media_id' => $media->id]);

        $response = $this->post('/admin/media/' . $media->id . '/delete');

        // Redirect with a message rather than a 500 or a silent success: the file is still on
        // the live site.
        self::assertSame(303, $response->status());
        self::assertCount(1, $this->app->container()->get(MediaServiceInterface::class)->listForSite($this->siteId));
    }

    // ---------------------------------------------------------------- picker

    #[Test]
    public function thePickerOffersUseThisImageOnlyWhenPicking(): void
    {
        $this->uploadImage('pickable');
        $id = $this->draft('Picker Target');

        $this->loginAs($this->makeUser('boss', 'editor'));

        self::assertStringNotContainsString('Use this image', $this->body($this->get('/admin/media')));

        $picker = $this->body($this->get('/admin/media', [
            'pick' => 'featured',
            'content_id' => $id,
        ]));

        self::assertStringContainsString('Use this image', $picker);
        self::assertStringContainsString('Back to the article', $picker);
    }

    #[Test]
    public function aFeaturedImageCanBeSetAndCleared(): void
    {
        $media = $this->uploadImage('feature');
        $author = $this->makeUser('writer', 'contributor');
        $id = $this->draft('Wants A Picture', $author);

        $this->loginAs($author);

        $this->post('/admin/content/' . $id . '/featured', ['media_id' => $media->id]);

        self::assertSame((int) $media->id, (int) $this->content($id)->featuredMediaId);

        // The editor shows it, so a save cannot silently lose it.
        self::assertStringContainsString('panel__thumb', $this->body($this->get('/admin/content/' . $id)));

        $this->post('/admin/content/' . $id . '/featured', ['media_id' => 0]);

        self::assertNull($this->content($id)->featuredMediaId);
    }

    #[Test]
    public function anImageFromAnotherTenantCannotBeAttached(): void
    {
        $author = $this->makeUser('writer', 'contributor');
        $id = $this->draft('Cross Tenant Image', $author);

        $otherSite = $this->createSiteRow('other', 'other.example');

        $path = $this->path('uploads/foreign.png');
        @mkdir(dirname($path), 0775, true);
        imagepng(imagecreatetruecolor(10, 10), $path);

        $foreign = $this->app->container()->get(MediaServiceInterface::class)->upload(
            $otherSite,
            $author,
            new UploadedFile(
                tmpPath: $path,
                originalName: 'foreign.png',
                size: (int) filesize($path),
                clientMime: 'image/png',
                error: UPLOAD_ERR_OK,
            ),
        );

        $this->loginAs($author);

        // A 404, not a 403: confirming the row exists would leak another tenant's library.
        self::assertSame(
            404,
            $this->post('/admin/content/' . $id . '/featured', ['media_id' => $foreign->id])->status()
        );
        self::assertNull($this->content($id)->featuredMediaId);

        // The same id is equally unusable through the block picker.
        self::assertSame(
            404,
            $this->post('/admin/content/' . $id . '/block-image', [
                'media_id' => $foreign->id,
                'index' => 0,
            ])->status()
        );
    }

    #[Test]
    public function choosingAnImageForABlockWritesAnImageBlock(): void
    {
        $media = $this->uploadImage('inline');
        $id = $this->draft('Block Gets An Image');
        $this->loginAs($this->makeUser('boss', 'editor'));

        // Index 0 is the paragraph that exists, so this replaces it.
        $response = $this->post('/admin/content/' . $id . '/block-image', [
            'media_id' => $media->id,
            'index' => 0,
        ]);

        self::assertSame(303, $response->status());

        $blocks = $this->content($id)->blocks();

        self::assertSame('image', $blocks[0]['type']);
        self::assertSame('/uploads/' . $media->path, $blocks[0]['data']['url']);
        self::assertSame('An inline', $blocks[0]['data']['alt']);

        // The stored value is site-relative, so it survives a change of host.
        self::assertStringStartsWith('/uploads/', $blocks[0]['data']['url']);
    }

    #[Test]
    public function choosingAnImageForTheBlankTrailingRowAddsABlock(): void
    {
        $media = $this->uploadImage('appended');
        $id = $this->draft('One Paragraph Only');
        $this->loginAs($this->makeUser('boss', 'editor'));

        $before = $this->content($id)->blocks();
        self::assertCount(1, $before);

        // The editor always renders one blank row, whose index equals the current count.
        $response = $this->post('/admin/content/' . $id . '/block-image', [
            'media_id' => $media->id,
            'index' => 1,
        ]);

        self::assertSame(303, $response->status());

        $after = $this->content($id)->blocks();

        self::assertCount(2, $after);
        self::assertSame('image', $after[1]['type']);
        self::assertSame('paragraph', $after[0]['type'], 'The existing block must be untouched.');
    }

    #[Test]
    public function aBlockIndexBeyondTheEndIsRejected(): void
    {
        $media = $this->uploadImage('beyond');
        $id = $this->draft('Too Far');
        $this->loginAs($this->makeUser('boss', 'editor'));

        $response = $this->post('/admin/content/' . $id . '/block-image', [
            'media_id' => $media->id,
            'index' => 9,
        ]);

        self::assertSame(303, $response->status());
        self::assertCount(1, $this->content($id)->blocks(), 'Nothing may be written for an impossible index.');
    }

    #[Test]
    public function theFeaturedImageAppearsOnThePublicPage(): void
    {
        $media = $this->uploadImage('shown');
        $author = $this->makeUser('writer', 'contributor');
        $id = $this->draft('Shows Its Picture', $author);

        $this->loginAs($author);
        $this->post('/admin/content/' . $id . '/featured', ['media_id' => $media->id]);

        // Publishing directly keeps this test about rendering rather than about the workflow,
        // which AdminContentFlowTest already covers.
        $this->app->container()->get(ContentServiceInterface::class)
            ->setStatus($id, $this->siteId, 'published');

        $response = $this->app->handle(Request::create('GET', '/blog/shows-its-picture', $this->host));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('featured-image', $response->content());
        self::assertStringContainsString($media->path, $response->content());
        self::assertStringContainsString('An shown', $response->content(), 'The alt text must travel with it.');
    }
}
