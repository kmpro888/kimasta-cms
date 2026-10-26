<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CMS\Core\Filesystem\FileLock;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\TestCase;

#[CoversClass(FileLock::class)]
final class FileLockTest extends TestCase
{
    private function lock(): FileLock
    {
        return new FileLock($this->path('locks'));
    }

    public function testAcquireAndRelease(): void
    {
        $lock = $this->lock();

        self::assertTrue($lock->acquire('cron'));
        self::assertTrue($lock->isLocked('cron'));

        $lock->release('cron');

        self::assertFalse($lock->isLocked('cron'));
    }

    public function testSecondAcquireFailsWithoutTimeout(): void
    {
        $first = $this->lock();
        $second = $this->lock();

        self::assertTrue($first->acquire('cron'));
        self::assertFalse($second->acquire('cron'));
    }

    public function testLockIsReusableAfterRelease(): void
    {
        $lock = $this->lock();

        $lock->acquire('cron');
        $lock->release('cron');

        self::assertTrue($lock->acquire('cron'));
    }

    public function testReleaseWithoutAcquireIsSafe(): void
    {
        $this->lock()->release('never');

        self::assertTrue(true);
    }

    public function testUnsafeNamesAreSanitised(): void
    {
        $lock = $this->lock();

        self::assertTrue($lock->acquire('../../etc/passwd'));

        // Traversal characters become underscores and the file stays inside
        // the lock directory: nothing is written to ../../etc/passwd.
        $lockFile = $this->path('locks' . DIRECTORY_SEPARATOR . '.._.._etc_passwd.lock');

        self::assertFileExists($lockFile);
        self::assertSame(
            realpath($this->path('locks')),
            realpath(dirname($lockFile)),
            'Lock file must stay inside the lock directory.'
        );
    }

    public function testDestructorReleasesLocks(): void
    {
        $lock = $this->lock();
        $lock->acquire('cron');
        unset($lock);

        self::assertFalse($this->lock()->isLocked('cron'));
    }
}
