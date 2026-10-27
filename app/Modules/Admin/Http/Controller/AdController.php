<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Controller;

use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Http\AdminController;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Advertising\Contracts\AdvertisingServiceInterface;
use CMS\Modules\Cache\Contracts\PageCacheInterface;
use CMS\Modules\Advertising\Entity\Ad;
use CMS\Modules\Advertising\Support\PlacementRegistry;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * Ad placements.
 *
 * `ads.manage` existed in the capability catalogue and the advertising service could create, edit
 * and delete placements — the last module with a full service and no screen.
 *
 * The ad body is HTML, which is the point of an ad and also the risk: whoever holds this capability
 * can put arbitrary markup on every page. That is why it is `ads.manage` (administrator) and why the
 * screen says so instead of hiding it.
 */
final class AdController extends AdminController
{
    public function __construct(
        AdminView $view,
        SessionInterface $session,
        CsrfTokenManagerInterface $csrf,
        ActorProviderInterface $actors,
        private readonly AdvertisingServiceInterface $ads,
        private readonly PageCacheInterface $cache,
    ) {
        parent::__construct($view, $session, $csrf, $actors);
    }

    public function index(Request $request): Response
    {
        $this->requireCapability($request, 'ads.manage');

        return $this->page($request);
    }

    public function store(Request $request): Response
    {
        $this->requireCapability($request, 'ads.manage');

        $values = $this->values($request);
        $errors = $this->validate($values);

        if ($errors !== []) {
            return $this->page($request, $errors, $values, 422);
        }

        try {
            $this->ads->create($this->siteId($request), [
                'name' => $values['name'],
                'placement' => $values['placement'],
                'body_html' => $values['body_html'],
                'target_url' => $values['target_url'] !== '' ? $values['target_url'] : null,
                'is_active' => $values['is_active'],
                'priority' => $values['priority'],
            ]);
        } catch (\InvalidArgumentException $exception) {
            return $this->page($request, ['body_html' => [$exception->getMessage()]], $values, 422);
        }

        $this->flash('success', 'Ad created.');
        // Every page that renders this placement is now stale.
        $this->cache->flushSite($this->siteId($request));

        return $this->redirect('/admin/ads');
    }

    public function toggle(Request $request): Response
    {
        $this->requireCapability($request, 'ads.manage');

        $ad = $this->findAd($request);

        $this->ads->update((int) $ad->id, $this->siteId($request), ['is_active' => !$ad->isActive]);
        $this->cache->flushSite($this->siteId($request));

        $this->flash('success', $ad->isActive ? 'Ad switched off.' : 'Ad switched on.');

        return $this->redirect('/admin/ads');
    }

    public function destroy(Request $request): Response
    {
        $this->requireCapability($request, 'ads.manage');

        $ad = $this->findAd($request);
        $this->ads->delete((int) $ad->id, $this->siteId($request));
        $this->cache->flushSite($this->siteId($request));

        $this->flash('success', 'Ad deleted.');

        return $this->redirect('/admin/ads');
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, mixed>|null $values
     */
    private function page(Request $request, array $errors = [], ?array $values = null, int $status = 200): Response
    {
        return $this->html($request, 'ads/index', 'Advertising', [
            'ads' => $this->ads->listForSite($this->siteId($request)),
            // Only what the theme prints. An unsupported placement is still listed below the
            // form with the reason, because silently offering a choice that does nothing is worse
            // than saying the theme cannot render it.
            'placements' => PlacementRegistry::supported(),
            'unsupported' => PlacementRegistry::unsupported(),
            'errors' => $errors,
            'values' => $values ?? [
                'name' => '', 'placement' => PlacementRegistry::supported()[0], 'body_html' => '',
                'target_url' => '', 'is_active' => true, 'priority' => 0,
            ],
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Request $request): array
    {
        $placement = $this->stringParam($request, 'placement');

        return [
            'name' => trim($this->stringParam($request, 'name')),
            // A placement the theme cannot print is refused rather than silently rewritten to
            // `header`: an ad stored against a slot that does not exist is money spent on nothing,
            // and the operator would have no way to tell.
            'placement' => PlacementRegistry::isSupported($placement)
                ? $placement
                : PlacementRegistry::supported()[0],
            'body_html' => trim($this->stringParam($request, 'body_html')),
            'target_url' => trim($this->stringParam($request, 'target_url')),
            'is_active' => $this->stringParam($request, 'is_active') === '1',
            'priority' => max(0, $this->intParam($request, 'priority', 0)),
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, list<string>>
     */
    private function validate(array $values): array
    {
        $errors = [];

        if ($values['name'] === '') {
            $errors['name'] = ['An ad needs a name, so it can be recognised in this list.'];
        }

        if (mb_strlen((string) $values['body_html']) < 4) {
            $errors['body_html'] = ['The ad needs some markup to render.'];
        }

        if ($values['target_url'] !== '' && preg_match('#^https?://#i', (string) $values['target_url']) !== 1) {
            $errors['target_url'] = ['The link must be an absolute http(s) URL, or empty.'];
        }

        return $errors;
    }

    private function findAd(Request $request): Ad
    {
        foreach ($this->ads->listForSite($this->siteId($request)) as $ad) {
            if ((int) $ad->id === $this->intParam($request, 'id')) {
                return $ad;
            }
        }

        throw \CMS\Core\Http\HttpException::notFound();
    }
}
