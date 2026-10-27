<?php

declare(strict_types=1);

namespace Tests\Contract\I18n;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The catalogues and the views must agree.
 *
 * Two failure modes this catches, both invisible until a translator sees them:
 *
 *  - a view uses a key no catalogue defines, so the page shows `nav.something` to a reader;
 *  - a catalogue drifts from the reference language, so a locale is silently half-translated.
 */
final class CatalogCoverageTest extends TestCase
{
    /** @return array<string, string> */
    private function catalog(string $locale): array
    {
        $path = dirname(__DIR__, 3) . '/lang/' . $locale . '.php';

        self::assertFileExists($path, 'Missing catalogue: ' . $locale);

        $catalog = require $path;

        self::assertIsArray($catalog);

        return $catalog;
    }

    /**
     * @return list<string> keys used by views and templates
     */
    private function usedKeys(): array
    {
        $root = dirname(__DIR__, 3);
        $keys = [];

        $directories = [
            $root . '/app/Modules/Admin/Resources/views',
            $root . '/themes',
        ];

        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                preg_match_all('/->t\(\s*[\'"]([a-z0-9_.]+)[\'"]/i', $source, $matches);

                foreach ($matches[1] as $key) {
                    $keys[$key] = true;
                }
            }
        }

        ksort($keys);

        return array_keys($keys);
    }

    #[Test]
    public function everyKeyUsedByAViewExistsInTheReferenceLanguage(): void
    {
        $catalog = $this->catalog('en');
        $missing = [];

        foreach ($this->usedKeys() as $key) {
            if (!array_key_exists($key, $catalog)) {
                $missing[] = $key;
            }
        }

        self::assertSame([], $missing, 'Views use keys the English catalogue does not define.');
    }

    #[Test]
    public function everyCatalogueHasTheSameKeysAsTheReference(): void
    {
        $reference = array_keys($this->catalog('en'));
        sort($reference);

        foreach (['id'] as $locale) {
            $keys = array_keys($this->catalog($locale));
            sort($keys);

            self::assertSame(
                $reference,
                $keys,
                'lang/' . $locale . '.php does not have the same keys as lang/en.php, so it is '
                    . 'half-translated in a way nobody would notice.'
            );
        }
    }

    #[Test]
    public function noCatalogueKeyIsDead(): void
    {
        $used = $this->usedKeys();
        $dead = [];

        foreach (array_keys($this->catalog('en')) as $key) {
            if (!in_array($key, $used, true)) {
                $dead[] = $key;
            }
        }

        self::assertSame([], $dead, 'Catalogue keys nobody uses are rot; remove them or use them.');
    }

    #[Test]
    public function theReferenceLanguageIsNotAccidentallyTranslated(): void
    {
        // A copy-paste that puts Indonesian into lang/en.php would make the fallback useless.
        $english = $this->catalog('en');
        $indonesian = $this->catalog('id');

        // A few words are the same in both languages; they are named rather than allowed
        // wholesale, so an accidental copy-paste still fails.
        $sameByDesign = ['nav.media', 'nav.plugins'];

        $identical = array_values(array_diff(
            array_keys(array_intersect_assoc($english, $indonesian)),
            $sameByDesign
        ));

        self::assertCount(
            0,
            $identical,
            'These keys are identical in both languages and are probably untranslated: '
                . implode(', ', $identical)
        );
    }
}
