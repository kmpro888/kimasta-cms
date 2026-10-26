<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Api\Http\ApiController;
use CMS\Modules\Plugin\Contracts\PluginManagerInterface;
use CMS\Modules\Plugin\Exceptions\PluginNotInstalledException;
use CMS\Modules\Plugin\Exceptions\PluginNotFoundException;

/**
 * Plugin platform endpoints.
 *
 * Two capabilities, deliberately split:
 *
 *  - `plugins.manage` to install or uninstall, because installation runs schema
 *    changes and is an operator decision;
 *  - `themes.manage` is not borrowed for activation — enabling a plugin on a site is
 *    `plugins.manage` too, since a plugin executes arbitrary contributed code.
 *
 * Activation is per tenant; the site is always the resolved one, never supplied.
 */
final class PluginController extends ApiController
{
    public function __construct(private readonly PluginManagerInterface $plugins)
    {
    }

    /**
     * GET /plugins
     */
    public function index(Request $request): Response
    {
        $this->requireCapability($request, 'plugins.manage');
        $siteId = $this->siteId($request);

        $state = $this->plugins->state();
        $active = $this->plugins->activeFor($siteId);

        $data = [];

        foreach ($this->plugins->discover() as $name => $manifest) {
            $data[] = [
                'name' => $manifest->name,
                'title' => $manifest->title,
                'version' => $manifest->version,
                'description' => $manifest->description,
                'author' => $manifest->author,
                'installed' => $state[$name]['installed'] ?? false,
                'active' => in_array($name, $active, true),
                'active_sites' => $state[$name]['active_sites'] ?? [],
            ];
        }

        return $this->ok($data);
    }

    /**
     * POST /plugins/{name}/install
     */
    public function install(Request $request): Response
    {
        $this->requireCapability($request, 'plugins.manage');
        $name = $this->nameParam($request);

        try {
            $manifest = $this->plugins->install($name);
        } catch (PluginNotFoundException) {
            throw HttpException::notFound();
        } catch (\Throwable) {
            // A plugin whose migration fails is not installed; do not expose the SQL.
            throw new HttpException(
                409,
                'The plugin could not be installed. Its migrations failed.',
                'PLUGIN_INSTALL_FAILED'
            );
        }

        return $this->ok($this->project($manifest, $request), [], 201);
    }

    /**
     * DELETE /plugins/{name}
     */
    public function uninstall(Request $request): Response
    {
        $this->requireCapability($request, 'plugins.manage');
        $name = $this->nameParam($request);

        try {
            $this->plugins->uninstall($name);
        } catch (PluginNotFoundException) {
            throw HttpException::notFound();
        }

        return new Response('', 204);
    }

    /**
     * POST /plugins/{name}/activate
     */
    public function activate(Request $request): Response
    {
        $this->requireCapability($request, 'plugins.manage');
        $siteId = $this->siteId($request);
        $name = $this->nameParam($request);

        try {
            $manifest = $this->plugins->activate($siteId, $name);
        } catch (PluginNotFoundException) {
            throw HttpException::notFound();
        } catch (PluginNotInstalledException) {
            throw new HttpException(
                409,
                'Install the plugin before activating it.',
                'PLUGIN_NOT_INSTALLED'
            );
        }

        return $this->ok($this->project($manifest, $request));
    }

    /**
     * POST /plugins/{name}/deactivate
     */
    public function deactivate(Request $request): Response
    {
        $this->requireCapability($request, 'plugins.manage');
        $siteId = $this->siteId($request);
        $name = $this->nameParam($request);

        try {
            $this->plugins->deactivate($siteId, $name);
        } catch (PluginNotFoundException) {
            throw HttpException::notFound();
        }

        return $this->ok([
            'name' => $name,
            'installed' => $this->plugins->isInstalled($name),
            'active' => $this->plugins->isActiveFor($siteId, $name),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function project(\CMS\Modules\Plugin\Entity\PluginManifest $manifest, Request $request): array
    {
        $siteId = $this->siteId($request);

        return [
            'name' => $manifest->name,
            'title' => $manifest->title,
            'version' => $manifest->version,
            'installed' => $this->plugins->isInstalled($manifest->name),
            'active' => $this->plugins->isActiveFor($siteId, $manifest->name),
            'active_sites' => $this->plugins->state()[$manifest->name]['active_sites'] ?? [],
        ];
    }

    /**
     * Plugin names are directory names, so the pattern is strict.
     */
    private function nameParam(Request $request): string
    {
        $name = $request->attribute('route.name');
        $name = is_string($name) ? trim($name) : '';

        if ($name === '' || preg_match('/^[a-z0-9][a-z0-9\-_]{0,189}$/', $name) !== 1) {
            throw HttpException::notFound();
        }

        return $name;
    }
}
