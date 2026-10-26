<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CMS\Core\Support\DebugGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\TestCase;

/**
 * Production debug guard.
 *
 * The guard exists so a misconfigured host fails at boot instead of disclosing
 * stack traces, SQL and paths to whoever triggers the first error.
 */
#[CoversClass(DebugGuard::class)]
final class DebugGuardTest extends TestCase
{
    #[Test]
    public function productionWithDebugEnabledThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/APP_DEBUG must be false when APP_ENV=production.*stack traces, SQL and filesystem paths/'
        );

        DebugGuard::assertSafe('production', true);
    }

    #[Test]
    public function productionWithoutDebugIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        DebugGuard::assertSafe('production', false);
    }

    #[Test]
    public function localWithDebugIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        DebugGuard::assertSafe('local', true);
    }

    #[Test]
    public function testingWithDebugIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        DebugGuard::assertSafe('testing', true);
    }

    #[Test]
    public function developmentWithDebugIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        DebugGuard::assertSafe('development', true);
    }

    #[Test]
    #[DataProvider('productionSpellings')]
    public function theEnvironmentNameIsComparedCaseInsensitively(string $appEnv): void
    {
        $this->expectException(RuntimeException::class);

        DebugGuard::assertSafe($appEnv, true);
    }

    /** @return iterable<string, array{string}> */
    public static function productionSpellings(): iterable
    {
        yield 'verbatim' => ['production'];
        yield 'upper case' => ['PRODUCTION'];
        yield 'mixed case' => ['Production'];
    }
}
