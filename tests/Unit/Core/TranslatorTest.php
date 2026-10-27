<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CMS\Core\I18n\Translator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The translator, on its own.
 *
 * The interesting behaviour is the fallback chain, because that is what a half-translated site
 * depends on: locale → default locale → the key itself. Never an empty string, never an exception
 * in the middle of rendering a page.
 */
#[CoversClass(Translator::class)]
final class TranslatorTest extends TestCase
{
    private function translator(string $locale, string $fallback = 'en'): Translator
    {
        return new Translator($locale, dirname(__DIR__, 3), $fallback);
    }

    #[Test]
    public function itReturnsTheTranslationForTheLocale(): void
    {
        self::assertSame('Beranda', $this->translator('id')->t('nav.dashboard'));
        self::assertSame('Dashboard', $this->translator('en')->t('nav.dashboard'));
    }

    #[Test]
    public function aRegionalLocaleFallsBackToItsLanguage(): void
    {
        // `id_ID` has no catalogue of its own; `id` does.
        self::assertSame('Beranda', $this->translator('id_ID')->t('nav.dashboard'));
    }

    #[Test]
    public function aMissingKeyReturnsTheKeyRatherThanNothing(): void
    {
        self::assertSame('nav.nonexistent', $this->translator('id')->t('nav.nonexistent'));
    }

    #[Test]
    public function aMissingTranslationFallsBackToTheDefaultLocale(): void
    {
        $translator = new Translator('bb', $this->fixturePath(), 'aa');

        // The fixture catalogs share only one key; the other exists in the fallback only.
        // Distinct locale codes are used because the catalogue cache is static per locale.
        self::assertSame('Bersama', $translator->t('shared'));
        self::assertSame('Fallback only', $translator->t('fallback_only'));
    }

    #[Test]
    public function parametersAreInterpolated(): void
    {
        self::assertSame('5 komentar', $this->translator('id')->t('public.comments.many', ['count' => 5]));
    }

    #[Test]
    public function anUnknownLocaleFallsBackInsteadOfFailing(): void
    {
        // Not a valid locale at all: the loader refuses it and the default language answers.
        self::assertSame('Dashboard', $this->translator('../etc/passwd')->t('nav.dashboard'));
    }

    #[Test]
    public function hasReportsWhetherAKeyExists(): void
    {
        self::assertTrue($this->translator('id')->has('nav.dashboard'));
        self::assertFalse($this->translator('id')->has('nav.nonexistent'));
    }

    #[Test]
    public function theLocaleIsReportedBack(): void
    {
        self::assertSame('id', $this->translator('id')->locale());
        self::assertSame('en', $this->translator('')->locale(), 'An empty locale is the default one.');
    }

    private function fixturePath(): string
    {
        $root = sys_get_temp_dir() . '/cms_i18n_' . getmypid();
        @mkdir($root . '/lang', 0775, true);

        file_put_contents($root . '/lang/aa.php', "<?php return ['shared' => 'Shared', 'fallback_only' => 'Fallback only'];");
        file_put_contents($root . '/lang/bb.php', "<?php return ['shared' => 'Bersama'];");

        return $root;
    }
}
