<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Media;

use CMS\Modules\Media\Image\ImageProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * Image backend capability detection and transformation.
 *
 * The point of these tests is that the engine must degrade rather than assume a
 * library: shared hosting frequently has GD without WebP, or Imagick with a
 * restricted format list.
 */
#[CoversClass(ImageProcessor::class)]
final class ImageProcessorTest extends TestCase
{
    private function makePng(int $width = 400, int $height = 300): string
    {
        $path = $this->path('source.png');

        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        $colour = imagecolorallocate($image, 20, 120, 200);
        imagefilledrectangle($image, 0, 0, $width, $height, $colour);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    #[Test]
    public function reportsAKnownDriver(): void
    {
        self::assertContains((new ImageProcessor())->driver(), ['imagick', 'gd', 'none']);
    }

    #[Test]
    public function availabilityMatchesTheDriver(): void
    {
        $processor = new ImageProcessor();

        self::assertSame($processor->driver() !== 'none', $processor->isAvailable());
    }

    #[Test]
    public function supportsOriginalFormat(): void
    {
        self::assertTrue((new ImageProcessor())->supportsFormat('original'));
    }

    #[Test]
    public function rejectsAnUnknownFormat(): void
    {
        self::assertFalse((new ImageProcessor())->supportsFormat('tiff'));
        self::assertFalse((new ImageProcessor())->supportsFormat('svg'));
    }

    #[Test]
    public function inspectsImageDimensions(): void
    {
        $processor = new ImageProcessor();

        if (!$processor->isAvailable()) {
            self::markTestSkipped('No image backend available.');
        }

        $info = $processor->inspect($this->makePng(320, 240));

        self::assertNotNull($info);
        self::assertSame(320, $info['width']);
        self::assertSame(240, $info['height']);
        self::assertSame('image/png', $info['mime']);
    }

    #[Test]
    public function inspectReturnsNullForAMissingFile(): void
    {
        self::assertNull((new ImageProcessor())->inspect($this->path('nope.png')));
    }

    #[Test]
    public function inspectReturnsNullForNonImageBytes(): void
    {
        $path = $this->path('not-an-image.png');
        file_put_contents($path, 'this is definitely not an image');

        self::assertNull((new ImageProcessor())->inspect($path));
    }

    #[Test]
    public function scalesDownToFitWithoutUpscaling(): void
    {
        $processor = new ImageProcessor();

        if (!$processor->isAvailable()) {
            self::markTestSkipped('No image backend available.');
        }

        $source = $this->makePng(400, 300);
        $destination = $this->path('out.png');

        $result = $processor->transform($source, $destination, 'png', 200, 200);

        self::assertNotNull($result);
        self::assertFileExists($destination);
        self::assertLessThanOrEqual(200, $result['width']);
        self::assertLessThanOrEqual(200, $result['height']);
        // Aspect ratio preserved (400x300 -> 200x150).
        self::assertSame(200, $result['width']);
        self::assertSame(150, $result['height']);
    }

    #[Test]
    public function neverUpscalesASmallImage(): void
    {
        $processor = new ImageProcessor();

        if (!$processor->isAvailable()) {
            self::markTestSkipped('No image backend available.');
        }

        $source = $this->makePng(80, 60);
        $destination = $this->path('small.png');

        $result = $processor->transform($source, $destination, 'png', 640, 640);

        self::assertNotNull($result);
        self::assertSame(80, $result['width']);
        self::assertSame(60, $result['height']);
    }

    #[Test]
    public function transformReturnsNullForAMissingSource(): void
    {
        $processor = new ImageProcessor();

        self::assertNull($processor->transform(
            $this->path('missing.png'),
            $this->path('out.png'),
            'png'
        ));
    }

    #[Test]
    public function stripMetadataSucceedsOrReportsFailureHonestly(): void
    {
        $processor = new ImageProcessor();

        if (!$processor->isAvailable()) {
            self::markTestSkipped('No image backend available.');
        }

        $path = $this->makePng(50, 50);

        // The contract allows a backend to refuse; what must never happen is a
        // false claim of success on a missing file.
        self::assertTrue($processor->stripMetadata($path));
        self::assertFalse($processor->stripMetadata($this->path('missing.png')));
    }

    #[Test]
    public function webpSupportIsReportedConsistently(): void
    {
        $processor = new ImageProcessor();

        if (!$processor->isAvailable()) {
            self::markTestSkipped('No image backend available.');
        }

        $supported = $processor->supportsFormat('webp');

        self::assertIsBool($supported);

        if (!$supported) {
            // A backend without WebP must refuse rather than emit a broken file.
            self::assertNull($processor->transform(
                $this->makePng(100, 100),
                $this->path('no-webp.webp'),
                'webp'
            ));
        }
    }
}
