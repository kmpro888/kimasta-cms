<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CMS\Core\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\TestCase;

#[CoversClass(Filesystem::class)]
final class FilesystemTest extends TestCase
{
    private function fs(): Filesystem
    {
        return new Filesystem($this->tempDir);
    }

    public function testPutAndGetRoundTrip(): void
    {
        $fs = $this->fs();
        $fs->put('a/b/c.txt', 'hello');

        self::assertTrue($fs->exists('a/b/c.txt'));
        self::assertSame('hello', $fs->get('a/b/c.txt'));
    }

    public function testGetReturnsNullForMissingFile(): void
    {
        self::assertNull($this->fs()->get('nope.txt'));
    }

    public function testPutCreatesMissingDirectories(): void
    {
        $fs = $this->fs();
        $fs->put('deep/nested/tree/file.txt', 'x');

        self::assertTrue($fs->isDirectory('deep/nested/tree'));
    }

    public function testPutOverwritesExistingContent(): void
    {
        $fs = $this->fs();
        $fs->put('f.txt', 'first');
        $fs->put('f.txt', 'second');

        self::assertSame('second', $fs->get('f.txt'));
    }

    public function testNoTemporaryFilesRemainAfterWrite(): void
    {
        $fs = $this->fs();
        $fs->put('clean.txt', 'value');

        $entries = scandir($this->tempDir) ?: [];
        $temp = array_filter($entries, static fn (string $e): bool => str_starts_with($e, '.cms_tmp_'));

        self::assertSame([], array_values($temp));
    }

    public function testAppend(): void
    {
        $fs = $this->fs();
        $fs->put('log.txt', "a\n");
        $fs->append('log.txt', "b\n");

        self::assertSame("a\nb\n", $fs->get('log.txt'));
    }

    public function testDelete(): void
    {
        $fs = $this->fs();
        $fs->put('gone.txt', 'x');

        self::assertTrue($fs->delete('gone.txt'));
        self::assertFalse($fs->exists('gone.txt'));
        self::assertTrue($fs->delete('gone.txt'), 'Deleting a missing file is idempotent.');
    }

    public function testDeleteDirectoryIsRecursive(): void
    {
        $fs = $this->fs();
        $fs->put('tree/one.txt', '1');
        $fs->put('tree/sub/two.txt', '2');

        self::assertTrue($fs->deleteDirectory('tree'));
        self::assertFalse($fs->isDirectory('tree'));
    }

    public function testReadLinesRespectsLimit(): void
    {
        $fs = $this->fs();
        $fs->put('lines.txt', "1\n2\n3\n4\n");

        self::assertSame(['1', '2'], $fs->readLines('lines.txt', 2));
    }
}
