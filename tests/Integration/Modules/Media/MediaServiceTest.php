<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Media;

use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Media\Entity\Media;
use CMS\Modules\Media\Entity\UploadedFile;
use CMS\Modules\Media\Exceptions\MediaInUseException;
use CMS\Modules\Media\Exceptions\MediaNotFoundException;
use CMS\Modules\Media\Image\ImageProcessor;
use CMS\Modules\Media\Repository\PdoMediaRepository;
use CMS\Modules\Media\Service\MediaService;
use CMS\Modules\Media\Support\NullMediaUsageChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\Support\ImageFixtures;
use Tests\Support\ModuleDatabaseTestCase;
use Tests\Support\RecordingEventDispatcher;

/**
 * Media upload and lifecycle against a real filesystem and database.
 */
#[CoversClass(MediaService::class)]
final class MediaServiceTest extends ModuleDatabaseTestCase
{
    private MediaServiceInterface $service;

    private PdoMediaRepository $repository;

    private RecordingEventDispatcher $events;

    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();

        if (!ImageFixtures::available()) {
            self::markTestSkipped('GD is required to generate image fixtures.');
        }

        $this->uploads = $this->path('public/uploads');
        mkdir($this->uploads, 0775, true);

        $this->repository = new PdoMediaRepository($this->db);
        $this->events = new RecordingEventDispatcher();

        $this->service = new MediaService(
            $this->repository,
            new ImageProcessor(),
            new NullMediaUsageChecker(),
            $this->events,
            new FrozenClock(),
            $this->uploads,
            10485760,
        );
    }

    private function uploadPng(int $siteId, string $name = 'photo.png', int $width = 400, int $height = 300): Media
    {
        $source = $this->path('incoming-' . bin2hex(random_bytes(4)) . '.png');
        $made = ImageFixtures::makePng($source, $width, $height);
        self::assertNotNull($made);

        return $this->service->upload($siteId, 1, UploadedFile::fake($source, $name));
    }

    #[Test]
    public function uploadsAPng(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $media = $this->uploadPng($siteId);

        self::assertSame('image/png', $media->mimeType);
        self::assertSame('png', $media->extension);
        self::assertSame(400, $media->width);
        self::assertSame(300, $media->height);
        self::assertGreaterThan(0, $media->filesize);
        self::assertTrue($media->isImage());
    }

    #[Test]
    public function uploadsAJpeg(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $source = $this->path('incoming.jpg');
        self::assertNotNull(ImageFixtures::makeJpeg($source, 300, 200));

        $media = $this->service->upload($siteId, 1, UploadedFile::fake($source, 'photo.jpg'));

        self::assertSame('image/jpeg', $media->mimeType);
        self::assertSame('jpg', $media->extension);
    }

    #[Test]
    public function storesUnderTheSiteScopedYearMonthPath(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $media = $this->uploadPng($siteId);

        // FrozenClock is 2026-01-01, so the path is /{site}/2026/01/.
        self::assertSame($siteId . '/2026/01', dirname($media->path));
        self::assertFileExists($this->uploads . '/' . str_replace('/', DIRECTORY_SEPARATOR, $media->path));
    }

    #[Test]
    public function theStoredFilenameIsGeneratedNotTakenFromTheClient(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $media = $this->uploadPng($siteId, 'My Holiday Photo.png');

        $stored = basename($media->path);

        self::assertStringStartsWith('my-holiday-photo-', $stored);
        self::assertStringEndsWith('.png', $stored);
        // The client name is kept only as display metadata.
        self::assertSame('My Holiday Photo.png', $media->originalName);
    }

    #[Test]
    public function twoUploadsOfTheSameNameDoNotCollide(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $first = $this->uploadPng($siteId, 'same.png');
        $second = $this->uploadPng($siteId, 'same.png');

        self::assertNotSame($first->path, $second->path);
    }

    #[Test]
    public function derivesTheConfiguredVariants(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $media = $this->uploadPng($siteId, 'variants.png', 1600, 1200);

        $variants = $this->repository->variantsFor((int) $media->id, $siteId);
        $names = array_values(array_unique(array_map(
            static fn ($variant): string => $variant->variant,
            $variants
        )));

        sort($names);

        self::assertSame(['large', 'medium', 'thumbnail'], $names);
    }

    #[Test]
    public function aThumbnailIsActuallySmaller(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $media = $this->uploadPng($siteId, 'big.png', 1600, 1200);

        $variants = $this->repository->variantsFor((int) $media->id, $siteId);

        foreach ($variants as $variant) {
            if ($variant->variant === 'thumbnail') {
                self::assertLessThanOrEqual(150, (int) $variant->width);
                self::assertLessThanOrEqual(150, (int) $variant->height);
                self::assertFileExists(
                    $this->uploads . '/' . str_replace('/', DIRECTORY_SEPARATOR, $variant->path)
                );

                return;
            }
        }

        self::fail('No thumbnail variant was produced.');
    }

    #[Test]
    public function generatesWebpWhenTheBackendSupportsIt(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $processor = new ImageProcessor();

        if (!$processor->supportsFormat('webp')) {
            self::markTestSkipped('This backend cannot encode WebP.');
        }

        $media = $this->uploadPng($siteId, 'webp-source.png', 1600, 1200);

        $formats = array_map(
            static fn ($variant): string => $variant->format,
            $this->repository->variantsFor((int) $media->id, $siteId)
        );

        self::assertContains('webp', $formats);
    }

    #[Test]
    public function uploadEmitsMediaCreated(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $media = $this->uploadPng($siteId);

        self::assertSame(['media.created'], $this->events->names());

        $event = $this->events->firstOf('media.created');
        self::assertNotNull($event);
        self::assertSame($media->id, $event->get('id'));
        self::assertSame($siteId, $event->siteId());
    }

    #[Test]
    public function altTextDefaultsToTheSlugifiedName(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $media = $this->uploadPng($siteId, 'Sunset Over The Bay.png');

        self::assertSame('sunset-over-the-bay', $media->altText);
    }

    #[Test]
    public function explicitAltTextIsKept(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $source = $this->path('alt.png');
        self::assertNotNull(ImageFixtures::makePng($source));

        $media = $this->service->upload($siteId, 1, UploadedFile::fake($source, 'x.png'), 'A blue square');

        self::assertSame('A blue square', $media->altText);
    }

    #[Test]
    public function updatesAltTextAndCaption(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $media = $this->uploadPng($siteId);

        $updated = $this->service->update((int) $media->id, $siteId, [
            'alt_text' => 'Updated alt',
            'caption' => 'A caption',
        ]);

        self::assertSame('Updated alt', $updated->altText);
        self::assertSame('A caption', $updated->caption);
    }

    #[Test]
    public function updateEmitsMediaUpdated(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $media = $this->uploadPng($siteId);

        $this->events->clear();
        $this->service->update((int) $media->id, $siteId, ['alt_text' => 'Changed']);

        self::assertSame(['media.updated'], $this->events->names());
    }

    #[Test]
    public function getReturnsTheRow(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $media = $this->uploadPng($siteId);

        self::assertSame($media->id, $this->service->get((int) $media->id, $siteId)->id);
    }

    #[Test]
    public function getThrowsForAnUnknownId(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(MediaNotFoundException::class);

        $this->service->get(999, $siteId);
    }

    #[Test]
    public function listIsScopedToTheSite(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');

        $this->uploadPng($first);
        $this->uploadPng($second);

        self::assertCount(1, $this->service->listForSite($first));
        self::assertCount(1, $this->service->listForSite($second));
    }

    #[Test]
    public function deleteRemovesTheRowAndItsFiles(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $media = $this->uploadPng($siteId);

        $absolute = $this->uploads . '/' . str_replace('/', DIRECTORY_SEPARATOR, $media->path);
        self::assertFileExists($absolute);

        self::assertTrue($this->service->delete((int) $media->id, $siteId));

        self::assertFileDoesNotExist($absolute);
        self::assertSame([], $this->repository->variantsFor((int) $media->id, $siteId));
    }

    #[Test]
    public function deleteEmitsMediaDeleted(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $media = $this->uploadPng($siteId);

        $this->events->clear();
        $this->service->delete((int) $media->id, $siteId);

        self::assertSame(['media.deleted'], $this->events->names());
    }

    #[Test]
    public function deletingMediaInUseIsRefusedWithoutForce(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $media = $this->uploadPng($siteId);

        $usage = new class implements \CMS\Modules\Media\Contracts\MediaUsageCheckerInterface {
            public function countReferences(int $mediaId, int $siteId, ?string $path = null): int
            {
                return 3;
            }
        };

        $guarded = new MediaService(
            $this->repository,
            new ImageProcessor(),
            $usage,
            $this->events,
            new FrozenClock(),
            $this->uploads,
        );

        try {
            $guarded->delete((int) $media->id, $siteId);
            self::fail('Deleting referenced media was allowed.');
        } catch (MediaInUseException $exception) {
            self::assertSame(409, $exception->status());
        }

        // Still present after the refusal.
        self::assertNotNull($this->repository->findById((int) $media->id, $siteId));
    }

    #[Test]
    public function forceDeleteOverridesTheReferenceGuard(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $media = $this->uploadPng($siteId);

        $usage = new class implements \CMS\Modules\Media\Contracts\MediaUsageCheckerInterface {
            public function countReferences(int $mediaId, int $siteId, ?string $path = null): int
            {
                return 3;
            }
        };

        $guarded = new MediaService(
            $this->repository,
            new ImageProcessor(),
            $usage,
            $this->events,
            new FrozenClock(),
            $this->uploads,
        );

        self::assertTrue($guarded->delete((int) $media->id, $siteId, true));
        self::assertNull($this->repository->findById((int) $media->id, $siteId));
    }

    #[Test]
    public function deletingUnknownMediaThrows(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(MediaNotFoundException::class);

        $this->service->delete(999, $siteId);
    }

    #[Test]
    public function countReflectsTheSiteOnly(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');

        $this->uploadPng($first);
        $this->uploadPng($first);
        $this->uploadPng($second);

        self::assertSame(2, $this->repository->countBySite($first));
        self::assertSame(1, $this->repository->countBySite($second));
    }
}
