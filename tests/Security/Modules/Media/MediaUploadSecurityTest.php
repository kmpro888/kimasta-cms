<?php

declare(strict_types=1);

namespace Tests\Security\Modules\Media;

use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Media\Entity\UploadedFile;
use CMS\Modules\Media\Exceptions\InvalidUploadException;
use CMS\Modules\Media\Exceptions\MediaTooLargeException;
use CMS\Modules\Media\Exceptions\UnsupportedMediaTypeException;
use CMS\Modules\Media\Image\ImageProcessor;
use CMS\Modules\Media\Repository\PdoMediaRepository;
use CMS\Modules\Media\Service\MediaService;
use CMS\Modules\Media\Support\NullMediaUsageChecker;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\Support\ImageFixtures;
use Tests\Support\ModuleDatabaseTestCase;
use Tests\Support\RecordingEventDispatcher;

/**
 * Upload security.
 *
 * The upload endpoint is the only place an anonymous or low-privilege user can
 * cause bytes to be written into the web root, so the rejection paths matter more
 * than the happy path. Each test here corresponds to a known attack shape.
 */
final class MediaUploadSecurityTest extends ModuleDatabaseTestCase
{
    private MediaServiceInterface $service;

    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploads = $this->path('public/uploads');
        mkdir($this->uploads, 0775, true);

        $this->service = new MediaService(
            new PdoMediaRepository($this->db),
            new ImageProcessor(),
            new NullMediaUsageChecker(),
            new RecordingEventDispatcher(),
            new FrozenClock(),
            $this->uploads,
            10485760,
        );
    }

    private function tempFile(string $name, string $contents): string
    {
        $path = $this->path($name);
        file_put_contents($path, $contents);

        return $path;
    }

    #[Test]
    public function aPhpFileRenamedAsAnImageIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $path = $this->tempFile('evil.png', "<?php system(\$_GET['c']); ?>");

        $this->expectException(UnsupportedMediaTypeException::class);

        $this->service->upload($siteId, 1, UploadedFile::fake($path, 'evil.png'));
    }

    #[Test]
    public function aDoubleExtensionWithPhpIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $path = $this->tempFile('payload.jpg', 'anything');

        $this->expectException(InvalidUploadException::class);

        $this->service->upload($siteId, 1, UploadedFile::fake($path, 'shell.php.jpg'));
    }

    #[Test]
    public function anExecutableExtensionIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $path = $this->tempFile('script.php', '<?php echo 1;');

        $this->expectException(UnsupportedMediaTypeException::class);

        $this->service->upload($siteId, 1, UploadedFile::fake($path, 'script.php'));
    }

    #[Test]
    public function aNonImageExtensionIsRejectedEvenWithImageBytes(): void
    {
        if (!ImageFixtures::available()) {
            self::markTestSkipped('GD is required to generate image fixtures.');
        }

        $siteId = $this->createSiteRow('main', 'example.com');

        $path = $this->path('real.png');
        self::assertNotNull(ImageFixtures::makePng($path));

        // Real image bytes, but a name the whitelist does not allow.
        $this->expectException(UnsupportedMediaTypeException::class);

        $this->service->upload($siteId, 1, UploadedFile::fake($path, 'real.svg'));
    }

    #[Test]
    public function aClientSuppliedMimeTypeCannotOverrideInspection(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $path = $this->tempFile('liar.png', 'not an image at all');

        try {
            $this->service->upload(
                $siteId,
                1,
                UploadedFile::fake($path, 'liar.png', 'image/png')
            );
            self::fail('A claimed image/png over non-image bytes was accepted.');
        } catch (UnsupportedMediaTypeException) {
            self::assertTrue(true);
        }
    }

    #[Test]
    public function anOversizedUploadIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $path = $this->tempFile('big.png', str_repeat('x', 200));

        $small = new MediaService(
            new PdoMediaRepository($this->db),
            new ImageProcessor(),
            new NullMediaUsageChecker(),
            new RecordingEventDispatcher(),
            new FrozenClock(),
            $this->uploads,
            100,
        );

        $this->expectException(MediaTooLargeException::class);

        $small->upload($siteId, 1, UploadedFile::fake($path, 'big.png'));
    }

    #[Test]
    public function anEmptyUploadIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $path = $this->tempFile('empty.png', '');

        $this->expectException(InvalidUploadException::class);

        $this->service->upload($siteId, 1, UploadedFile::fake($path, 'empty.png'));
    }

    #[Test]
    public function anUploadWithAPhpErrorIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $file = new UploadedFile(
            tmpPath: $this->path('nonexistent.png'),
            originalName: 'x.png',
            size: 10,
            clientMime: 'image/png',
            error: UPLOAD_ERR_INI_SIZE,
        );

        $this->expectException(InvalidUploadException::class);

        $this->service->upload($siteId, 1, $file);
    }

    #[Test]
    public function pathTraversalInTheFilenameCannotEscapeTheUploadsDirectory(): void
    {
        if (!ImageFixtures::available()) {
            self::markTestSkipped('GD is required to generate image fixtures.');
        }

        $siteId = $this->createSiteRow('main', 'example.com');

        $path = $this->path('traversal.png');
        self::assertNotNull(ImageFixtures::makePng($path));

        // "../../../../evil.png" has multiple dots, so it is refused outright;
        // the generated filename means traversal could not succeed even if it
        // were allowed through.
        $this->expectException(InvalidUploadException::class);

        $this->service->upload($siteId, 1, UploadedFile::fake($path, '../../../../evil.png'));
    }

    #[Test]
    public function theStoredPathAlwaysStaysInsideTheUploadsRoot(): void
    {
        if (!ImageFixtures::available()) {
            self::markTestSkipped('GD is required to generate image fixtures.');
        }

        $siteId = $this->createSiteRow('main', 'example.com');

        $path = $this->path('good.png');
        self::assertNotNull(ImageFixtures::makePng($path));

        $media = $this->service->upload($siteId, 1, UploadedFile::fake($path, 'good.png'));

        self::assertStringNotContainsString('..', $media->path);
        self::assertStringStartsWith($siteId . '/', $media->path);

        $absolute = realpath($this->uploads . '/' . str_replace('/', DIRECTORY_SEPARATOR, $media->path));
        $root = realpath($this->uploads);

        self::assertNotFalse($absolute);
        self::assertNotFalse($root);
        self::assertStringStartsWith($root, $absolute);
    }

    #[Test]
    public function noUploadedFileIsEverNamedWithAPhpExtension(): void
    {
        if (!ImageFixtures::available()) {
            self::markTestSkipped('GD is required to generate image fixtures.');
        }

        $siteId = $this->createSiteRow('main', 'example.com');

        foreach (['a.png', 'b.jpg'] as $index => $name) {
            $source = $this->path('scan-' . $index . '.' . pathinfo($name, PATHINFO_EXTENSION));

            if (pathinfo($name, PATHINFO_EXTENSION) === 'png') {
                self::assertNotNull(ImageFixtures::makePng($source));
            } else {
                self::assertNotNull(ImageFixtures::makeJpeg($source));
            }

            $media = $this->service->upload($siteId, 1, UploadedFile::fake($source, $name));

            self::assertContains($media->extension, ['jpg', 'png', 'webp']);
            self::assertStringNotContainsString('.php', $media->path);
        }
    }

    #[Test]
    public function anUploadWithoutASiteIsRejected(): void
    {
        if (!ImageFixtures::available()) {
            self::markTestSkipped('GD is required to generate image fixtures.');
        }

        $path = $this->path('nos.png');
        self::assertNotNull(ImageFixtures::makePng($path));

        $this->expectException(InvalidUploadException::class);

        $this->service->upload(0, 1, UploadedFile::fake($path, 'nos.png'));
    }

    #[Test]
    public function anUploadWithoutAnUploaderIsRejected(): void
    {
        if (!ImageFixtures::available()) {
            self::markTestSkipped('GD is required to generate image fixtures.');
        }

        $siteId = $this->createSiteRow('main', 'example.com');

        $path = $this->path('nou.png');
        self::assertNotNull(ImageFixtures::makePng($path));

        $this->expectException(InvalidUploadException::class);

        $this->service->upload($siteId, 0, UploadedFile::fake($path, 'nou.png'));
    }
}
