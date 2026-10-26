<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case.
 *
 * Provides a per-test temporary directory so filesystem, cache, lock and log
 * tests never share state and never touch the real storage directory.
 */
abstract class TestCase extends BaseTestCase
{
    protected string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'cms_test_'
            . bin2hex(random_bytes(8));

        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }

        $this->tempDir = '';

        parent::tearDown();
    }

    protected function path(string $relative = ''): string
    {
        if ($relative === '') {
            return $this->tempDir;
        }

        return $this->tempDir . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
    }

    protected function removeDirectory(string $directory): void
    {
        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}