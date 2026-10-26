<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CMS\Core\Support\Doctor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Deployment preflight checks.
 *
 * Each case here is a failure that actually happened while building this platform, so the
 * tests pin the verdict rather than the wording.
 */
#[CoversClass(Doctor::class)]
final class DoctorTest extends TestCase
{
    #[Test]
    public function aSupportedPhpVersionPasses(): void
    {
        self::assertSame(Doctor::OK, Doctor::checkPhpVersion('8.3.6')['level']);
    }

    #[Test]
    public function anOldPhpVersionFails(): void
    {
        self::assertSame(Doctor::FAILURE, Doctor::checkPhpVersion('8.0.30')['level']);
    }

    #[Test]
    public function theFloorMatchesTheComposerRequirement(): void
    {
        // composer.json requires >=8.2. A doctor that accepted 8.1 would pass a server on
        // which `composer install` refuses to run, which is the exact disagreement this check
        // exists to catch.
        self::assertSame(Doctor::FAILURE, Doctor::checkPhpVersion('8.1.27')['level']);
        self::assertSame(Doctor::OK, Doctor::checkPhpVersion('8.2.0')['level']);
    }

    #[Test]
    public function extensionNamesAreComparedCaseInsensitively(): void
    {
        // get_loaded_extensions() reports `PDO` while the requirement says `pdo`; a
        // case-sensitive diff reported a missing PDO on a host where PDO was loaded.
        $result = Doctor::checkExtensions(['PDO', 'pdo_mysql', 'MBSTRING', 'Json', 'fileinfo']);

        self::assertSame(Doctor::OK, $result['level'], $result['message']);
    }

    #[Test]
    public function aMissingRequiredExtensionFails(): void
    {
        $result = Doctor::checkExtensions(['pdo', 'mbstring', 'json', 'fileinfo']);

        self::assertSame(Doctor::FAILURE, $result['level']);
        self::assertStringContainsString('pdo_mysql', $result['message']);
    }

    #[Test]
    public function aMissingOptionalExtensionOnlyWarns(): void
    {
        // Image handling degrades but pages still render.
        $result = Doctor::checkOptionalExtensions(['gd']);

        self::assertSame(Doctor::WARNING, $result['level']);
        self::assertStringContainsString('exif', $result['message']);
    }

    #[Test]
    public function productionWithDebugOnIsAFailure(): void
    {
        $result = Doctor::checkDebug('production', true);

        self::assertSame(Doctor::FAILURE, $result['level']);
        self::assertStringContainsString('APP_DEBUG=false', $result['message']);
    }

    #[Test]
    public function productionWithDebugOffPasses(): void
    {
        self::assertSame(Doctor::OK, Doctor::checkDebug('production', false)['level']);
    }

    #[Test]
    public function localWithDebugOnPasses(): void
    {
        self::assertSame(Doctor::OK, Doctor::checkDebug('local', true)['level']);
    }

    #[Test]
    public function theEnvironmentNameIsCaseInsensitive(): void
    {
        self::assertSame(Doctor::FAILURE, Doctor::checkDebug('PRODUCTION', true)['level']);
        self::assertSame(Doctor::FAILURE, Doctor::checkDebug('Production', true)['level']);
    }

    #[Test]
    public function anEmptyAppKeyWarnsAndASetOnePasses(): void
    {
        self::assertSame(Doctor::WARNING, Doctor::checkAppKey('')['level']);
        self::assertSame(Doctor::OK, Doctor::checkAppKey(str_repeat('a', 64))['level']);
    }

    #[Test]
    public function nonInnoDbTablesAreAFailure(): void
    {
        // Foreign keys and transactions are not enforced on MyISAM, so this is not a
        // performance note: the schema's guarantees silently stop holding.
        $result = Doctor::checkStorageEngine('InnoDB', ['contents', 'comments']);

        self::assertSame(Doctor::FAILURE, $result['level']);
        self::assertStringContainsString('contents', $result['message']);
    }

    #[Test]
    public function aNonInnoDbDefaultEngineWarnsWhenAllTablesAreFine(): void
    {
        $result = Doctor::checkStorageEngine('MyISAM', []);

        self::assertSame(Doctor::WARNING, $result['level']);
    }

    #[Test]
    public function pendingMigrationsAreAFailure(): void
    {
        self::assertSame(Doctor::FAILURE, Doctor::checkMigrations(3)['level']);
        self::assertSame(Doctor::OK, Doctor::checkMigrations(0)['level']);
    }

    #[Test]
    public function anUnwritablePathIsAFailure(): void
    {
        $result = Doctor::checkPaths(['storage' => true, 'storage/cache' => false]);

        self::assertSame(Doctor::FAILURE, $result['level']);
        self::assertStringContainsString('storage/cache', $result['message']);
    }

    #[Test]
    public function everyWritablePathPasses(): void
    {
        self::assertSame(Doctor::OK, Doctor::checkPaths(['storage' => true])['level']);
    }

    #[Test]
    public function aMissingUploadsGuardIsAFailure(): void
    {
        // Without it an uploaded file is remote code execution.
        self::assertSame(Doctor::FAILURE, Doctor::checkUploadsGuard(false)['level']);
        self::assertSame(Doctor::OK, Doctor::checkUploadsGuard(true)['level']);
    }

    #[Test]
    public function aMissingSitemapOnlyWarns(): void
    {
        // /sitemap.xml 404s until cron runs once; the site still works.
        self::assertSame(Doctor::WARNING, Doctor::checkSitemap(false)['level']);
    }

    #[Test]
    public function theSummaryCountsEveryLevel(): void
    {
        $counts = Doctor::summarise([
            'a' => ['level' => Doctor::OK, 'message' => ''],
            'b' => ['level' => Doctor::OK, 'message' => ''],
            'c' => ['level' => Doctor::WARNING, 'message' => ''],
            'd' => ['level' => Doctor::FAILURE, 'message' => ''],
        ]);

        self::assertSame(2, $counts[Doctor::OK]);
        self::assertSame(1, $counts[Doctor::WARNING]);
        self::assertSame(1, $counts[Doctor::FAILURE]);
    }
}
