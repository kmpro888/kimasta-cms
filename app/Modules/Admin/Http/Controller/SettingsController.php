<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Controller;

use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Http\AdminController;
use CMS\Modules\Audit\Contracts\AuditLoggerInterface;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Cache\Contracts\PageCacheInterface;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;
use CMS\Modules\Theme\Contracts\ThemeRegistryInterface;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * Site settings: the name, the locale, the timezone, the base URL, and the theme.
 *
 * Changing any of these changes every page: the name appears in the header, the locale in `<html
 * lang>`, the timezone in every rendered date, the theme everywhere. So every save flushes this
 * site's page cache. That is the one place a whole-site flush is right — the alternative is a cache
 * full of pages that disagree with the settings that produced them.
 *
 * The domain is shown but not editable here. Moving it must move the domain mapping and regenerate
 * the compiled map, and the old domain stops resolving at once: a typo would lock the site out of
 * its own administration, which is not a change to offer behind a text field.
 */
final class SettingsController extends AdminController
{
    /** A short, deliberately conservative list; the field accepts any valid zone. */
    private const COMMON_TIMEZONES = [
        'UTC', 'Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura', 'Asia/Singapore',
        'Asia/Kuala_Lumpur', 'Asia/Bangkok', 'Asia/Tokyo', 'Australia/Sydney',
        'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Los_Angeles',
    ];

    public function __construct(
        AdminView $view,
        SessionInterface $session,
        CsrfTokenManagerInterface $csrf,
        ActorProviderInterface $actors,
        private readonly SiteServiceInterface $sites,
        private readonly ThemeRegistryInterface $themes,
        private readonly PageCacheInterface $cache,
        private readonly AuditLoggerInterface $audit,
    ) {
        parent::__construct($view, $session, $csrf, $actors);
    }

    public function index(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');

        return $this->page($request);
    }

    public function save(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');

        $siteId = $this->siteId($request);
        $site = $this->site($request);

        $name = trim($this->stringParam($request, 'name'));
        $locale = trim($this->stringParam($request, 'default_locale'));
        $timezone = trim($this->stringParam($request, 'timezone'));
        $baseUrl = trim($this->stringParam($request, 'base_url'));

        $errors = [];

        if ($name === '') {
            $errors['name'] = ['A site needs a name.'];
        }

        if ($locale === '' || preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $locale) !== 1) {
            $errors['default_locale'] = ['Use a locale such as en, en_US or id.'];
        }

        if ($timezone === '') {
            $errors['timezone'] = ['A timezone is required; dates are rendered in it.'];
        } else {
            try {
                new \DateTimeZone($timezone);
            } catch (\Exception) {
                $errors['timezone'] = ['That is not a timezone PHP recognises.'];
            }
        }

        if ($baseUrl !== '' && preg_match('#^https?://[^\s/]+#i', $baseUrl) !== 1) {
            $errors['base_url'] = ['Use an absolute URL such as https://example.com, or leave it empty.'];
        }

        if ($errors !== []) {
            return $this->page($request, $errors, [
                'name' => $name,
                'default_locale' => $locale,
                'timezone' => $timezone,
                'base_url' => $baseUrl,
            ], 422);
        }

        $this->sites->update($siteId, [
            'name' => $name,
            'default_locale' => $locale,
            'timezone' => $timezone,
            'base_url' => rtrim($baseUrl, '/'),
        ]);

        // Every page carries at least one of these values.
        $this->cache->flushSite($siteId);

        $this->audit->record($this->siteId($request), $this->actor($request), 'settings.updated', 'site', $this->siteId($request));
        $this->flash('success', 'Settings saved. The page cache was cleared.');

        return $this->redirect('/admin/settings');
    }

    public function theme(Request $request): Response
    {
        $this->requireCapability($request, 'themes.manage');

        $siteId = $this->siteId($request);
        $name = $this->stringParam($request, 'theme');

        if (!$this->themes->has($name)) {
            $this->flash('error', 'That theme is not installed.');

            return $this->redirect('/admin/settings');
        }

        $this->themes->activate($siteId, $name);

        $this->cache->flushSite($siteId);

        $this->flash('success', 'Theme changed to ' . $name . '.');

        return $this->redirect('/admin/settings');
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, mixed>|null $values
     */
    private function page(Request $request, array $errors = [], ?array $values = null, int $status = 200): Response
    {
        $site = $this->site($request);
        $actor = $this->actor($request);

        $installed = [];

        foreach ($this->themes->all() as $theme) {
            $installed[] = $theme->name;
        }

        $current = $values ?? [
            'name' => $site->name(),
            'default_locale' => $site->defaultLocale(),
            'timezone' => $site->timezone(),
            'base_url' => $site->baseUrl(),
        ];

        // Keep the current zone in the list even when it is not one of the common ones.
        $zones = self::COMMON_TIMEZONES;

        if (!in_array($current['timezone'], $zones, true)) {
            array_unshift($zones, $current['timezone']);
        }

        return $this->html($request, 'settings/index', 'Site settings', [
            'values' => $current,
            'errors' => $errors,
            'zones' => $zones,
            'site' => $site,
            'themes' => $installed,
            'activeTheme' => $this->themes->activeFor($this->siteId($request))->name,
            'canManageTheme' => $actor->can('themes.manage'),
        ], $status);
    }
}
