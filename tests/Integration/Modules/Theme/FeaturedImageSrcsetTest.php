<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Theme;

use CMS\Core\Http\Request;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Media\Entity\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * The featured image carries a `srcset`.
 *
 * The upload pipeline has always derived thumbnail/medium/large variants. Nothing printed them, so
 * every visitor downloaded the full-size file: a feature that was paid for and never delivered.
 *
 * These tests pin both halves — that a real, large image gets several candidates in **one format**,
 * and that a small image (whose variants are all one width) gets none, rather than three identical
 * candidates pretending to be a choice.
 */
#[CoversClass(\CMS\Modules\Theme\Rendering\PageViewModelFactory::class)]
final class FeaturedImageSrcsetTest extends AppTestCase
{
    private int $uploaderId = 0;

    private function uploader(): int
    {
        return $this->uploaderId > 0 ? $this->uploaderId : ($this->uploaderId = $this->makeUser('uploader', 'editor'));
    }

    private function uploadImage(int $width, int $height): \CMS\Modules\Media\Entity\Media
    {
        $path = $this->path('uploads/srcset-' . $width . 'x' . $height . '.png');
        @mkdir(dirname($path), 0775, true);

        $image = imagecreatetruecolor($width, $height);
        imagepng($image, $path);

        return $this->app->container()->get(MediaServiceInterface::class)->upload(
            $this->siteId,
            $this->uploader(),
            new UploadedFile(
                tmpPath: $path,
                originalName: 'photo.png',
                size: (int) filesize($path),
                clientMime: 'image/png',
                error: UPLOAD_ERR_OK,
            ),
            'A test image',
        );
    }

    private function articleWithImage(int $mediaId): void
    {
        $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => 'With a picture',
            'slug' => 'with-a-picture',
            'author_id' => $this->uploader(),
            'status' => 'published',
            'featured_media_id' => $mediaId,
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);
    }

    private function render(): string
    {
        $response = $this->app->handle(Request::create('GET', '/blog/with-a-picture', $this->host));

        self::assertSame(200, $response->status(), 'The article must render.');

        return $response->content();
    }

    #[Test]
    public function aLargeImageGetsSeveralCandidatesInOneFormat(): void
    {
        $media = $this->uploadImage(1600, 900);
        $this->articleWithImage((int) $media->id);

        $html = $this->render();

        self::assertStringContainsString('srcset="', $html, 'A large image must offer candidates.');
        self::assertStringContainsString('sizes="', $html);

        preg_match('/srcset="([^"]+)"/', $html, $matches);
        $candidates = array_values(array_filter(array_map('trim', explode(',', $matches[1] ?? ''))));

        self::assertGreaterThanOrEqual(3, count($candidates), 'Thumbnail, medium, large and the original.');

        $widths = [];

        foreach ($candidates as $candidate) {
            self::assertMatchesRegularExpression('/^\S+ \d+w$/', $candidate, 'Every candidate needs a width.');
            $widths[] = (int) rtrim(substr($candidate, strrpos($candidate, ' ') + 1), 'w');

            // One format per srcset: a WebP mixed into a PNG list is a format switch, not a size.
            self::assertStringNotContainsString('.webp', $candidate);
        }

        self::assertSame($widths, array_values(array_unique($widths)), 'Widths must not repeat.');
        self::assertSame($widths, array_values(array_filter($widths, static fn (int $w): bool => $w > 0)));
        sort($widths);
        self::assertSame($widths, array_values($widths), 'Candidates are printed smallest first.');

        self::assertStringContainsString('1600w', $matches[1], 'The original is the largest candidate.');
    }

    #[Test]
    public function aSmallImageGetsNoSrcsetRatherThanDuplicateCandidates(): void
    {
        // Every variant of a 40x30 image is the same width, so there is nothing to choose between.
        $media = $this->uploadImage(40, 30);
        $this->articleWithImage((int) $media->id);

        $html = $this->render();

        self::assertStringContainsString('featured-image', $html, 'The image itself must still render.');
        self::assertStringNotContainsString('srcset="', $html);
    }

    #[Test]
    public function anArticleWithoutAFeaturedImageHasNoImageBlock(): void
    {
        $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => 'No picture',
            'slug' => 'no-picture',
            'author_id' => $this->uploader(),
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);

        $html = $this->app->handle(Request::create('GET', '/blog/no-picture', $this->host))->content();

        self::assertStringNotContainsString('srcset="', $html);
        self::assertStringNotContainsString('featured-image', $html);
    }
}
