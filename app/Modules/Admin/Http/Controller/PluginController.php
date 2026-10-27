<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Http\AdminController;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Plugin\Contracts\PluginManagerInterface;
use CMS\Modules\Plugin\Exceptions\PluginNotFoundException;
use CMS\Modules\Plugin\Exceptions\PluginNotInstalledException;
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
        $this->flash('success', 'Uninstalled ' . $name . '. Tables it created are left in the database.');

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

        $this->flash('success', 'Deactivated on this site. It stays installed.');

        return $this->redirect('/admin/plugins');
    }

    private function page(Request $request): Response
    {
        $siteId = $this->siteId($request);
        $manifests = $this->plugins->discover();
        $state = $this->plugins->state();

        $rows = [];

        foreach ($manifests as $name => $manifest) {
            $status = $state[$name] ?? ['installed' => false, 'active_sites' => []];
            $activeSites = array_map('intval', (array) ($status['active_sites'] ?? []));

            $rows[] = [
                'manifest' => $manifest,
                'installed' => (bool) ($status['installed'] ?? false),
                'activeHere' => in_array($siteId, $activeSites, true),
                'activeSites' => count($activeSites),
            ];
        }

        return $this->html($request, 'plugins/index', 'Plugins', ['rows' => $rows]);
    }

    private function pluginName(Request $request): string
    {
        return trim($this->stringParam($request, 'name'));
    }
}
