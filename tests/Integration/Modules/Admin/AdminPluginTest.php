<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use CMS\Modules\Plugin\Contracts\PluginManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * The plugins screen.
 *
 * Three states the screen keeps apart, because conflating them is how a tenant's experiment ends up
 * changing another tenant's schema:
 *
 *   discovered  the directory exists and the manifest parses
 *   installed   registered platform-wide, migrations have run
 *   active      switched on for *this* site
 */
#[CoversClass(\CMS\Modules\Admin\Http\Controller\PluginController::class)]
final class AdminPluginTest extends AdminTestCase
{
    private const PLUGIN = 'example-plugin';

    private function plugins(): PluginManagerInterface
    {
        return $this->app->container()->get(PluginManagerInterface::class);
    }

    #[Test]
    public function theScreenRequiresThePluginsCapability(): void
    {
        $this->loginAs($this->makeUser('boss', 'editor'));

        // `plugins.manage` belongs to the administrator: installing runs migrations.
        self::assertSame(403, $this->get('/admin/plugins')->status());
        self::assertSame(403, $this->post('/admin/plugins/install', ['name' => self::PLUGIN])->status());
    }

    #[Test]
    public function itListsThePluginFoundOnDisk(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $response = $this->get('/admin/plugins');
        $html = $this->body($response);

        self::assertSame(200, $response->status());
        self::assertStringContainsString(self::PLUGIN, $html);
        self::assertStringContainsString('Not installed', $html);
    }

    #[Test]
    public function installingRegistersThePlugin(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $response = $this->post('/admin/plugins/install', ['name' => self::PLUGIN]);

        self::assertSame(303, $response->status());
        self::assertTrue($this->plugins()->isInstalled(self::PLUGIN));
        self::assertFalse(
            $this->plugins()->isActiveFor($this->siteId, self::PLUGIN),
            'Installing must not switch it on for anybody.'
        );
    }

    #[Test]
    public function switchingOnIsPerSiteAndDoesNotLeak(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');
        $this->plugins()->install(self::PLUGIN);
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $response = $this->post('/admin/plugins/activate', ['name' => self::PLUGIN]);

        self::assertSame(303, $response->status());
        self::assertTrue($this->plugins()->isActiveFor($this->siteId, self::PLUGIN));
        self::assertFalse(
            $this->plugins()->isActiveFor($otherSite, self::PLUGIN),
            'Activation is a per-tenant switch, not a platform one.'
        );
    }

    #[Test]
    public function switchingOffLeavesItInstalled(): void
    {
        $this->plugins()->install(self::PLUGIN);
        $this->plugins()->activate($this->siteId, self::PLUGIN);

        $this->loginAs($this->makeUser('admin', 'administrator'));
        $response = $this->post('/admin/plugins/deactivate', ['name' => self::PLUGIN]);

        self::assertSame(303, $response->status());
        self::assertFalse($this->plugins()->isActiveFor($this->siteId, self::PLUGIN));
        self::assertTrue($this->plugins()->isInstalled(self::PLUGIN), 'Switching off is not uninstalling.');
    }

    #[Test]
    public function activatingBeforeInstallingIsExplainedRatherThanThrown(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $response = $this->post('/admin/plugins/activate', ['name' => self::PLUGIN]);

        self::assertSame(303, $response->status());
        self::assertFalse($this->plugins()->isActiveFor($this->siteId, self::PLUGIN));
    }

    #[Test]
    public function uninstallingRemovesTheRegistration(): void
    {
        $this->plugins()->install(self::PLUGIN);
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $response = $this->post('/admin/plugins/uninstall', ['name' => self::PLUGIN]);

        self::assertSame(303, $response->status());
        self::assertFalse($this->plugins()->isInstalled(self::PLUGIN));
    }

    #[Test]
    public function anUnknownPluginIsA404(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        self::assertSame(404, $this->post('/admin/plugins/install', ['name' => 'no-such-plugin'])->status());
        self::assertSame(404, $this->post('/admin/plugins/activate', ['name' => 'no-such-plugin'])->status());
    }

    #[Test]
    public function everyActionNeedsACsrfToken(): void
    {
        $this->plugins()->install(self::PLUGIN);
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $response = $this->post('/admin/plugins/activate', ['name' => self::PLUGIN], false);

        self::assertSame(403, $response->status());
        self::assertFalse($this->plugins()->isActiveFor($this->siteId, self::PLUGIN));
    }

    #[Test]
    public function theStatusChangesAreReflectedOnTheScreen(): void
    {
        $this->plugins()->install(self::PLUGIN);
        $this->plugins()->activate($this->siteId, self::PLUGIN);

        $this->loginAs($this->makeUser('admin', 'administrator'));

        $html = $this->body($this->get('/admin/plugins'));

        self::assertStringContainsString('Active here', $html);
        self::assertStringContainsString('Switch off for this site', $html);
    }

    #[Test]
    public function thePluginsEntryIsOnlyInTheMenuForSomeoneWhoCanUseIt(): void
    {
        $this->loginAs($this->makeUser('boss', 'editor'));
        self::assertStringNotContainsString('href="/admin/plugins"', $this->body($this->get('/admin')));

        $this->loginAs($this->makeUser('admin', 'administrator'));
        self::assertStringContainsString('href="/admin/plugins"', $this->body($this->get('/admin')));
    }
}
