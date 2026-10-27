<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Http\AdminController;
use CMS\Modules\Audit\Contracts\AuditLoggerInterface;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Plugin\Contracts\PluginManagerInterface;
use CMS\Modules\Plugin\Exceptions\PluginNotFoundException;
use CMS\Modules\Plugin\Exceptions\PluginNotInstalledException;
use CMS\Modules\Plugin\Exceptions\PluginUpToDateException;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * Plugins: what is on disk, what is installed, and what is switched on for this site.
 *
 * Three states that people confuse, so the screen names them separately:
 *
 *   - **discovered** — the plugin directory exists and its manifest parses;
 *   - **installed** — the platform registered it and ran its migrations; this is one action for
 *     the whole installation;
 *   - **active** — it is switched on for *this* site. Activation is per tenant.
 *
 * Installation is an operator action because it runs migrations; activation is a per-site switch.
 * Keeping them apart is what stops one tenant's experiment from changing another tenant's schema.
 */
final class PluginController extends AdminController
{
    public function __construct(
        AdminView $view,
        SessionInterface $session,
        CsrfTokenManagerInterface $csrf,
        ActorProviderInterface $actors,
        private readonly PluginManagerInterface $plugins,
        private readonly AuditLoggerInterface $audit,
    ) {
        parent::__construct($view, $session, $csrf, $actors);
    }

    public function index(Request $request): Response
    {
        $this->requireCapability($request, 'plugins.manage');

        return $this->page($request);
    }

    public function install(Request $request): Response
    {
        $this->requireCapability($request, 'plugins.manage');

        $name = $this->pluginName($request);

        try {
            $this->plugins->install($name);
        } catch (PluginNotFoundException) {
            throw HttpException::notFound();
        }

        $this->audit->record($this->siteId($request), $this->actor($request), 'plugin.installed', 'plugin', null, ['plugin' => $name]);
        $this->flash('success', 'Installed ' . $name . '. Its migrations have run.');

        return $this->redirect('/admin/plugins');
    }

    public function uninstall(Request $request): Response
    {
        $this->requireCapability($request, 'plugins.manage');

        $name = $this->pluginName($request);

        try {
            $this->plugins->uninstall($name);
        } catch (PluginNotFoundException) {
            throw HttpException::notFound();
        }

        // Said plainly because it is true: uninstall removes the registration, not the data.
        $this->audit->record($this->siteId($request), $this->actor($request), 'plugin.uninstalled', 'plugin', null, ['plugin' => $name]);
        $this->flash('success', 'Uninstalled ' . $name . '. Tables it created are left in the database.');

        return $this->redirect('/admin/plugins');
    }

    /**
     * Bring an installed plugin up to the version on disk.
     *
     * The screen only offers this when there is something to do, and the manager still refuses a
     * no-op: a button that succeeds at nothing teaches an operator to distrust the button.
     */
    public function update(Request $request): Response
    {
        $this->requireCapability($request, 'plugins.manage');

        $name = $this->pluginName($request);

        try {
            $manifest = $this->plugins->update($name);
        } catch (PluginNotFoundException) {
            throw HttpException::notFound();
        } catch (PluginNotInstalledException) {
            $this->flash('error', 'Install ' . $name . ' before updating it.');

            return $this->redirect('/admin/plugins');
        } catch (PluginUpToDateException) {
            $this->flash('error', $name . ' is already up to date.');

            return $this->redirect('/admin/plugins');
        }

        $this->audit->record($this->siteId($request), $this->actor($request), 'plugin.updated', 'plugin', null, ['plugin' => $name, 'version' => $manifest->version]);
        $this->flash('success', 'Updated ' . $name . ' to ' . $manifest->version . '.');

        return $this->redirect('/admin/plugins');
    }
    public function activate(Request $request): Response
    {
        $this->requireCapability($request, 'plugins.manage');

        $name = $this->pluginName($request);

        try {
            $this->plugins->activate($this->siteId($request), $name);
        } catch (PluginNotFoundException) {
            throw HttpException::notFound();
        } catch (PluginNotInstalledException) {
            $this->flash('error', 'Install ' . $name . ' before switching it on.');

            return $this->redirect('/admin/plugins');
        }

        $this->audit->record($this->siteId($request), $this->actor($request), 'plugin.activated', 'plugin', null, ['plugin' => $name]);
        $this->flash('success', 'Activated on this site.');

        return $this->redirect('/admin/plugins');
    }

    public function deactivate(Request $request): Response
    {
        $this->requireCapability($request, 'plugins.manage');

        $name = $this->pluginName($request);

        try {
            $this->plugins->deactivate($this->siteId($request), $name);
        } catch (PluginNotFoundException) {
            throw HttpException::notFound();
        }

        $this->audit->record($this->siteId($request), $this->actor($request), 'plugin.deactivated', 'plugin', null, ['plugin' => $name]);
        $this->flash('success', 'Deactivated on this site. It stays installed.');

        return $this->redirect('/admin/plugins');
    }

    private function page(Request $request): Response
    {
        $siteId = $this->siteId($request);
        $manifests = $this->plugins->discover();
        $state = $this->plugins->state();
        // Installed plugins with a newer version on disk; the screen shows the button only for these.
        $updates = $this->plugins->updates();

        $rows = [];

        foreach ($manifests as $name => $manifest) {
            $status = $state[$name] ?? ['installed' => false, 'active_sites' => []];
            $activeSites = array_map('intval', (array) ($status['active_sites'] ?? []));

            $rows[] = [
                'manifest' => $manifest,
                'installed' => (bool) ($status['installed'] ?? false),
                'activeHere' => in_array($siteId, $activeSites, true),
                'activeSites' => count($activeSites),
                // The version recorded at install time, against the version on disk.
                'installedVersion' => $updates[$name]['installed'] ?? null,
                'outdated' => isset($updates[$name]),
            ];
        }

        return $this->html($request, 'plugins/index', 'Plugins', ['rows' => $rows]);
    }

    private function pluginName(Request $request): string
    {
        return trim($this->stringParam($request, 'name'));
    }
}
