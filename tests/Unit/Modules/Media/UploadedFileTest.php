<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Media;

use CMS\Modules\Media\Entity\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * Uploaded file value object.
 */
#[CoversClass(UploadedFile::class)]
final class UploadedFileTest extends TestCase
{
    #[Test]
    public function buildsFromAFilesEntry(): void
    {
        $file = UploadedFile::fromFilesArray([
            'tmp_name' => '/tmp/php123',
            'name' => 'Photo.JPG',
            'size' => 1234,
            'type' => 'image/jpeg',
            'error' => UPLOAD_ERR_OK,
        ]);

        self::assertSame('/tmp/php123', $file->tmpPath);
        self::assertSame('Photo.JPG', $file->originalName);
        self::assertSame(1234, $file->size);
        self::assertSame(UPLOAD_ERR_OK, $file->error);
        self::assertFalse($file->hasUploadError());
    }

    #[Test]
    public function detectsAnUploadError(): void
    {
        $file = UploadedFile::fromFilesArray(['error' => UPLOAD_ERR_INI_SIZE]);

        self::assertTrue($file->hasUploadError());
    }

    #[Test]
    public function extensionIsLowercased(): void
    {
        $file = UploadedFile::fromFilesArray(['name' => 'IMAGE.PNG']);

        self::assertSame('png', $file->extension());
    }

    #[Test]
    public function aSingleDotNameIsNotADoubleExtension(): void
    {
        $file = UploadedFile::fromFilesArray(['name' => 'photo.jpg']);

        self::assertFalse($file->hasDoubleExtension());
    }

    #[Test]
    public function aPhpJpgNameIsADoubleExtension(): void
    {
        $file = UploadedFile::fromFilesArray(['name' => 'shell.php.jpg']);

        self::assertTrue($file->hasDoubleExtension());
    }

    #[Test]
    public function aDottedBaseNameIsAlsoFlagged(): void
    {
        // "my.photo.jpg" is legitimate in the wild but still has two dots, so it
        // is refused rather than risked; the whitelist is the real defence and
        // this is the early, clearer error.
        $file = UploadedFile::fromFilesArray(['name' => 'my.photo.jpg']);

        self::assertTrue($file->hasDoubleExtension());
    }

    #[Test]
    public function fakeUsesTheRealFileSize(): void
    {
        $path = $this->path('fake.png');
        file_put_contents($path, str_repeat('x', 42));

        $file = UploadedFile::fake($path, 'fake.png');

        self::assertSame(42, $file->size);
        self::assertFalse($file->hasUploadError());
    }

    #[Test]
    public function fakeMarksAMissingFileAsErrored(): void
    {
        $file = UploadedFile::fake($this->path('missing.png'), 'missing.png');

        self::assertTrue($file->hasUploadError());
    }
}
