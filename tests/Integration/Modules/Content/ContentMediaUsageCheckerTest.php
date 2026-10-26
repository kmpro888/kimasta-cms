<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Content;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Media\Contracts\MediaUsageCheckerInterface;
use CMS\Modules\Media\Entity\Media;
use CMS\Modules\Media\Entity\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * The answer Content gives Media to "is this file still in use?".
 *
 * The defect this guards: only `featured_media_id` was counted, so a file shown inside an
 * article's body looked unused. Deleting it left a broken image on a published page.
 */
#[CoversClass(\CMS\Modules\Content\Support\ContentMediaUsageChecker::class)]
final class ContentMediaUsageCheckerTest extends AppTestCase
{
    /** Created once: a second user with the same username is a duplicate error, and every
     * article here can share one author. */
    private ?int $authorId = null;

    private function author(): int
    {
        return $this->authorId ??= $this->makeUser('writer', 'contributor');
    }
    private function upload(string $name = 'shot'): Media
    {
        $path = $this->path('uploads/' . $name . '.png');
        @mkdir(dirname($path), 0775, true);
        imagepng(imagecreatetruecolor(12, 12), $path);

        return $this->app->container()->get(MediaServiceInterface::class)->upload(
            $this->siteId,
            $this->author(),
            new UploadedFile(
                tmpPath: $path,
                originalName: $name . '.png',
                size: (int) filesize($path),
                clientMime: 'image/png',
                error: UPLOAD_ERR_OK,
            ),
        );
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function article(string $title, array $blocks, ?int $featuredMediaId = null, ?int $siteId = null): int
    {
        $attributes = [
            'type' => 'post',
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'author_id' => $this->author(),
            'status' => 'published',
            'content_json' => $blocks,
        ];

        if ($featuredMediaId !== null) {
            $attributes['featured_media_id'] = $featuredMediaId;
        }

        return (int) $this->app->container()->get(ContentServiceInterface::class)
            ->create($siteId ?? $this->siteId, $attributes)->id;
    }

    private function checker(): MediaUsageCheckerInterface
    {
        return $this->app->container()->get(MediaUsageCheckerInterface::class);
    }

    #[Test]
    public function anUnusedFileHasNoReferences(): void
    {
        $media = $this->upload();

        self::assertSame(0, $this->checker()->countReferences((int) $media->id, $this->siteId, $media->path));
    }

    #[Test]
    public function aFeaturedImageCountsAsAReference(): void
    {
        $media = $this->upload();

        $this->article('Featured Use', [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]], (int) $media->id);

        self::assertSame(1, $this->checker()->countReferences((int) $media->id, $this->siteId, $media->path));
    }

    #[Test]
    public function anImageInsideTheBodyCountsAsAReference(): void
    {
        $media = $this->upload();

        // This is the case that was missed: a block points at the file by URL, and the row's
        // featured_media_id stays null.
        $this->article('Body Use', [
            ['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Before.']],
            ['type' => 'image', 'version' => 1, 'data' => ['url' => $media->url(), 'alt' => 'Inside']],
        ]);

        self::assertSame(1, $this->checker()->countReferences((int) $media->id, $this->siteId, $media->path));
    }

    #[Test]
    public function theSameFileUsedTwiceInOneArticleCountsOncePerRow(): void
    {
        $media = $this->upload();

        $this->article('Twice In One', [
            ['type' => 'image', 'version' => 1, 'data' => ['url' => $media->url(), 'alt' => 'One']],
            ['type' => 'image', 'version' => 1, 'data' => ['url' => $media->url(), 'alt' => 'Two']],
        ]);

        // The question is "how many entities reference it", and the answer drives a message
        // shown to a person, so counting rows is the useful number.
        self::assertSame(1, $this->checker()->countReferences((int) $media->id, $this->siteId, $media->path));
    }

    #[Test]
    public function referencesAcrossSeveralArticlesAreCounted(): void
    {
        $media = $this->upload();

        $this->article('First User', [
            ['type' => 'image', 'version' => 1, 'data' => ['url' => $media->url(), 'alt' => 'A']],
        ]);
        $this->article('Second User', [
            ['type' => 'image', 'version' => 1, 'data' => ['url' => $media->url(), 'alt' => 'B']],
        ]);

        self::assertSame(2, $this->checker()->countReferences((int) $media->id, $this->siteId, $media->path));
    }

    #[Test]
    public function anotherSitesArticleIsNotCounted(): void
    {
        $media = $this->upload();
        $otherSite = $this->createSiteRow('other', 'other.example');

        $this->article(
            'Elsewhere',
            [['type' => 'image', 'version' => 1, 'data' => ['url' => $media->url(), 'alt' => 'A']]],
            null,
            $otherSite,
        );

        self::assertSame(0, $this->checker()->countReferences((int) $media->id, $this->siteId, $media->path));
    }

    #[Test]
    public function withoutAPathOnlyTheFeaturedReferenceIsSeen(): void
    {
        $media = $this->upload();

        $this->article('Body Only', [
            ['type' => 'image', 'version' => 1, 'data' => ['url' => $media->url(), 'alt' => 'A']],
        ]);

        // An implementation that only knows ids still answers correctly for the field it knows.
        self::assertSame(0, $this->checker()->countReferences((int) $media->id, $this->siteId));
    }

    #[Test]
    public function aSimilarLookingPathDoesNotMatch(): void
    {
        $media = $this->upload('real');

        // A different file whose path shares a prefix must not be counted for this one.
        $this->article('Different File', [
            ['type' => 'image', 'version' => 1, 'data' => ['url' => '/uploads/9/2026/10/unrelated-abc.png', 'alt' => 'A']],
        ]);

        self::assertSame(0, $this->checker()->countReferences((int) $media->id, $this->siteId, $media->path));
    }
}
