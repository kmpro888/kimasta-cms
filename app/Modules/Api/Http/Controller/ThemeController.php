<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Api\Http\ApiController;
use CMS\Modules\Api\Resources\ThemeResource;
use CMS\Modules\Theme\Contracts\ThemeRegistryInterface;
use CMS\Modules\Theme\Exceptions\ThemeNotFoundException;

/**
 * Theme endpoints: browse installed themes and switch the active one.
 *
 * Activation emits `theme.activated`, which the cache consumes, so switching through
 * the API invalidates the rendered pages exactly like an admin switch.
 */
final class ThemeController extends ApiController
{
    public function __construct(
        private readonly ThemeRegistryInterface $themes,
        private readonly ThemeResource $resource,
    ) {
    }

    /**
     * GET /themes
     */
    public function index(Request $request): Response
    {
        $this->requireCapability($request, 'settings.read');
        $siteId = $this->siteId($request);

        $active = $this->activeThemeName($siteId);

        $data = [];

        foreach ($this->themes->all() as $theme) {
            $data[] = ThemeResource::withActive($active)->toArray($theme);
        }

        return $this->ok($data);
    }

    /**
     * GET /themes/active
     */
    public function active(Request $request): Response
    {
        $this->requireCapability($request, 'settings.read');
        $siteId = $this->siteId($request);

        try {
            $theme = $this->themes->activeFor($siteId);
        } catch (ThemeNotFoundException) {
            throw HttpException::notFound();
        }

        return $this->ok($this->resource->toArray($theme));
    }

    /**
     * POST /themes/{name}/activate
     */
    public function activate(Request $request): Response
    {
        $this->requireCapability($request, 'themes.manage');
        $siteId = $this->siteId($request);

        $name = $request->attribute('route.name');
        $name = is_string($name) ? trim($name) : '';

        if ($name === '') {
            throw HttpException::notFound();
        }

        try {
            $theme = $this->themes->activate($siteId, $name);
        } catch (ThemeNotFoundException) {
            throw HttpException::notFound();
        }

        // Rebuild the projection with the freshly activated name, so `active` is
        // true in the response rather than reflecting whatever the injected
        // resource happened to be constructed with.
        return $this->ok(ThemeResource::withActive($theme->name)->toArray($theme));
    }

    private function activeThemeName(int $siteId): ?string
    {
        try {
            return $this->themes->activeFor($siteId)->name;
        } catch (ThemeNotFoundException) {
            return null;
        }
    }
}
