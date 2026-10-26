<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Controller;

use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Http\AdminController;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Contribution\Contracts\ContributorServiceInterface;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * The landing screen.
 *
 * It shows what this particular person can act on, not site statistics: a contributor needs
 * the draft they abandoned yesterday, a reviewer needs the queue. Showing everyone the same
 * charts would be decoration.
 */
final class DashboardController extends AdminController
{
    private const RECENT = 5;

    public function __construct(
        AdminView $view,
        SessionInterface $session,
        CsrfTokenManagerInterface $csrf,
        ActorProviderInterface $actors,
        private readonly ContributorServiceInterface $contributor,
        private readonly ContentQueryServiceInterface $query,
    ) {
        parent::__construct($view, $session, $csrf, $actors);
    }

    public function index(Request $request): Response
    {
        $actor = $this->requireCapability($request, 'content.read');
        $siteId = $this->siteId($request);
        $userId = (int) $actor->id();

        $drafts = $this->contributor->myDrafts($siteId, $userId, self::RECENT);
        $published = $this->contributor->myPublished($siteId, $userId, self::RECENT);

        // The review queue is only meaningful for someone who can act on it, so it is only
        // queried for them.
        $queue = [];

        if ($actor->can('workflow.review')) {
            $queue = $this->query->paginate($siteId, [
                'statuses' => ['submitted'],
                'limit' => self::RECENT,
                'order' => 'oldest',
            ])['items'];
        }

        return $this->html($request, 'dashboard', 'Dashboard', [
            'drafts' => $drafts,
            'published' => $published,
            'queue' => $queue,
            'canReview' => $actor->can('workflow.review'),
            'canCreate' => $actor->can('content.create'),
        ]);
    }
}
