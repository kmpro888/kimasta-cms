<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Theme;

use CMS\Modules\Site\Repository\PdoSiteRepository;
use CMS\Modules\Theme\Contracts\ThemeRegistryInterface;
use CMS\Modules\Theme\Exceptions\ThemeNotFoundException;
use CMS\Modules\Theme\Service\ThemeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\Support\ModuleDatabaseTestCase;
use Tests\Support\RecordingEventDispatcher;

/**
 * Theme discovery and activation.
 *
 * Discovery must survive a broken theme: a directory without a valid manifest is
 * skipped, not fatal, because one bad theme must not take the admin down.
 */
#[CoversClass(ThemeRegistry::class)]
final class ThemeRegistryTest extends ModuleDatabaseTestCase
{
    private string $themesRoot;

    private ThemeRegistryInterface $registry;

    private RecordingEventDispatcher $events;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->themesRoot = $this->path('themes');
        mkdir($this->themesRoot, 0775, true);

        $this->siteId = $this->createSiteRow('main', 'example.com');
        $this->events = new RecordingEventDispatcher();

        $this->registry = new ThemeRegistry(
            $this->themesRoot,
            new PdoSiteRepository($this->db),
            $this->events,
            new FrozenClock(),
        );
    }

    private function makeTheme(string $name, array $manifest = [], bool $withTemplates = true): void
    {
        $directory = $this->themesRoot . DIRECTORY_SEPARATOR . $name;
        mkdir($directory, 0775, true);

        if ($manifest !== [] || $manifest === []) {
            $contents = array_merge([
                'name' => $name,
                'title' => ucfirst($name),
                'version' => '1.0.0',
            ], $manifest);

            file_put_contents(
                $directory . DIRECTORY_SEPARATOR . 'theme.json',
                json_encode($contents)
            );
        }

        if ($withTemplates) {
            mkdir($directory . DIRECTORY_SEPARATOR . 'templates', 0775, true);
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'index.php', 'x');
        }
    }

    #[Test]
    public function discoversValidThemes(): void
    {
        $this->makeTheme('default');
        $this->makeTheme('minimal');

        $all = $this->registry->all();

        self::assertArrayHasKey('default', $all);
        self::assertArrayHasKey('minimal', $all);
        self::assertSame('1.0.0', $all['default']->version);
    }

    #[Test]
    public function aDirectoryWithoutAManifestIsNotATheme(): void
    {
        $this->makeTheme('default');
        mkdir($this->themesRoot . DIRECTORY_SEPARATOR . 'not-a-theme', 0775, true);

        self::assertFalse($this->registry->has('not-a-theme'));
        self::assertCount(1, $this->registry->all());
    }

    #[Test]
    public function aManifestWithoutAVersionIsRejected(): void
    {
        $directory = $this->themesRoot . DIRECTORY_SEPARATOR . 'broken';
        mkdir($directory, 0775, true);
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'theme.json', json_encode(['name' => 'broken']));

        self::assertFalse($this->registry->has('broken'));
    }

    #[Test]
    public function invalidJsonIsSkippedNotFatal(): void
    {
        $directory = $this->themesRoot . DIRECTORY_SEPARATOR . 'garbage';
        mkdir($directory, 0775, true);
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'theme.json', '{not json');

        $this->makeTheme('default');

        self::assertCount(1, $this->registry->all());
    }

    #[Test]
    public function theDirectoryNameWinsOverAManifestNameMismatch(): void
    {
        // A copied theme usually carries the old name in its manifest; the
        // filesystem stays authoritative.
        $this->makeTheme('renamed', ['name' => 'original']);

        self::assertTrue($this->registry->has('renamed'));
        self::assertFalse($this->registry->has('original'));
    }

    #[Test]
    public function getThrowsForAnUnknownTheme(): void
    {
        $this->expectException(ThemeNotFoundException::class);

        $this->registry->get('nope');
    }

    #[Test]
    public function activeForFallsBackToDefaultWhenNothingIsAssigned(): void
    {
        $this->makeTheme('default');
        $this->makeTheme('other');

        self::assertSame('default', $this->registry->activeFor($this->siteId)->name);
    }

    #[Test]
    public function activationPersistsTheChoiceOnTheSite(): void
    {
        $this->makeTheme('default');
        $this->makeTheme('minimal');

        $this->registry->activate($this->siteId, 'minimal');

        $site = (new PdoSiteRepository($this->db))->findById($this->siteId);

        self::assertNotNull($site);
        self::assertSame('minimal', $site->activeTheme());
    }

    #[Test]
    public function activationEmitsThemeActivated(): void
    {
        $this->makeTheme('default');
        $this->makeTheme('minimal');

        $this->registry->activate($this->siteId, 'minimal');

        self::assertSame(['theme.activated'], $this->events->names());

        $event = $this->events->firstOf('theme.activated');
        self::assertNotNull($event);
        self::assertSame('minimal', $event->get('theme'));
        self::assertSame('default', $event->get('previous_theme'));
        self::assertSame($this->siteId, $event->siteId());
    }

    #[Test]
    public function activationChangesWhatRendersWithoutTouchingContent(): void
    {
        $this->makeTheme('default');
        $this->makeTheme('minimal');

        $this->registry->activate($this->siteId, 'minimal');

        // A fresh registry instance (as a later request would build) sees the
        // assignment, so the switch persists.
        $fresh = new ThemeRegistry(
            $this->themesRoot,
            new PdoSiteRepository($this->db),
            new RecordingEventDispatcher(),
            new FrozenClock(),
        );

        self::assertSame('minimal', $fresh->activeFor($this->siteId)->name);
    }

    #[Test]
    public function activatingAnUnknownThemeThrows(): void
    {
        $this->makeTheme('default');

        $this->expectException(ThemeNotFoundException::class);

        $this->registry->activate($this->siteId, 'nope');
    }

    #[Test]
    public function anAssignedButUninstalledThemeFallsBackToDefault(): void
    {
        $this->makeTheme('default');

        // Point the site at a theme that is no longer on disk.
        (new PdoSiteRepository($this->db))->updateTheme($this->siteId, 'removed');

        self::assertSame('default', $this->registry->activeFor($this->siteId)->name);
    }

    #[Test]
    public function themesAreIsolatedPerSite(): void
    {
        $this->makeTheme('default');
        $this->makeTheme('minimal');

        $otherSite = $this->createSiteRow('other', 'other.example');

        $this->registry->activate($this->siteId, 'minimal');

        self::assertSame('minimal', $this->registry->activeFor($this->siteId)->name);
        self::assertSame('default', $this->registry->activeFor($otherSite)->name);
    }

    #[Test]
    public function pathForReturnsTheThemeDirectory(): void
    {
        $this->makeTheme('default');

        self::assertStringEndsWith('themes' . DIRECTORY_SEPARATOR . 'default', $this->registry->pathFor('default'));
    }

    #[Test]
    public function assetsUrlIsDerivedFromTheThemeName(): void
    {
        $this->makeTheme('default');

        self::assertSame('/themes/default/assets', $this->registry->get('default')->assetsUrl());
    }
}
