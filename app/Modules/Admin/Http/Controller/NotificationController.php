<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Http\AdminController;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Notification\Contracts\NotificationServiceInterface;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * The signed-in person's notifications, and the site's per-event switches.
 *
 * Two different scopes live behind one menu entry, and the difference matters: the list is
 * **mine**, so any signed-in person may read and clear it; the preferences are **the site's**,
 * because the service stores one switch per event and channel for the whole tenant, so changing
 * them requires `settings.update`.
 *
 * The PRD asks for an ON/OFF switch per event and channel per site. This screen is that switch;
 * until now only the API could reach it.
 */
final class NotificationController extends AdminController
{
    /** The events the service knows about, in the order they should be shown. */
    private const EVENT_LABELS = [
        'content.submitted' => 'Submitted for review',
        'content.revision_requested' => 'Revision requested',
        'content.approved' => 'Approved',
        'content.scheduled' => 'Scheduled',
        'content.published' => 'Published',
    ];

    private const CHANNEL_LABELS = [
        'internal' => 'In-app',
        'email' => 'Email',
    ];

    public function __construct(
        AdminView $view,
        SessionInterface $session,
        CsrfTokenManagerInterface $csrf,
        ActorProviderInterface $actors,
        private readonly NotificationServiceInterface $notifications,
    ) {
        parent::__construct($view, $session, $csrf, $actors);
    }

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $siteId = $this->siteId($request);
        $userId = (int) $actor->id();

        $unreadOnly = $this->stringParam($request, 'filter') === 'unread';

        return $this->html($request, 'notifications/index', 'Notifications', [
            'items' => $this->notifications->listForUser($siteId, $userId, $unreadOnly, 50),
            'unreadOnly' => $unreadOnly,
            'unread' => $this->notifications->unreadCount($siteId, $userId),
            'canManagePreferences' => $actor->can('settings.update'),
        ]);
    }

    public function markRead(Request $request): Response
    {
        $actor = $this->actor($request);
        $id = $this->intParam($request, 'id');

        // Scoped to the current user: marking somebody else's notification read must behave as
        // if it does not exist, not as if it were forbidden.
        if (!$this->notifications->markRead($id, $this->siteId($request), (int) $actor->id())) {
            throw HttpException::notFound();
        }

        return $this->redirect($this->listUrl($request));
    }

    public function markAllRead(Request $request): Response
    {
        $actor = $this->actor($request);

        $count = $this->notifications->markAllRead($this->siteId($request), (int) $actor->id());

        $this->flash('success', $count === 1 ? 'Marked 1 notification as read.' : 'Marked ' . $count . ' notifications as read.');

        return $this->redirect($this->listUrl($request));
    }

    public function preferences(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');

        return $this->preferencePage($request);
    }

    /**
     * Save the site's switches.
     *
     * A checkbox that is not sent means off, so every known event and channel is written from
     * the submitted form rather than only the ones present. Otherwise turning something off
     * would be impossible: an unchecked box never arrives.
     */
    public function updatePreferences(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');

        $siteId = $this->siteId($request);
        $submitted = $request->input('prefs', []);
        $submitted = is_array($submitted) ? $submitted : [];

        $saved = 0;

        foreach (array_keys(self::EVENT_LABELS) as $event) {
            foreach (array_keys(self::CHANNEL_LABELS) as $channel) {
                $raw = $submitted[$event][$channel] ?? null;
                $enabled = is_scalar($raw) && in_array((string) $raw, ['1', 'true', 'on', 'yes'], true);

                try {
                    $this->notifications->setPreference($siteId, $event, $channel, $enabled);
                    $saved++;
                } catch (\InvalidArgumentException) {
                    // An event or channel the service does not recognise is skipped rather than
                    // failing the whole save.
                }
            }
        }

        $this->flash('success', 'Saved ' . $saved . ' switch(es).');

        return $this->redirect('/admin/notifications/preferences');
    }

    private function preferencePage(Request $request): Response
    {
        $siteId = $this->siteId($request);
        $preferences = $this->notifications->preferences($siteId);

        // Show the catalogue order, but never hide an event the service knows and this list does
        // not: a new event must appear without a code change here.
        $events = [];

        foreach (array_keys(self::EVENT_LABELS) as $event) {
            if (array_key_exists($event, $preferences)) {
                $events[$event] = self::EVENT_LABELS[$event];
            }
        }

        foreach (array_keys($preferences) as $event) {
            $events[$event] ??= $event;
        }

        return $this->html($request, 'notifications/preferences', 'Notification settings', [
            'preferences' => $preferences,
            'events' => $events,
            'channels' => self::CHANNEL_LABELS,
        ]);
    }

    private function listUrl(Request $request): string
    {
        return $this->stringParam($request, 'filter') === 'unread'
            ? '/admin/notifications?filter=unread'
            : '/admin/notifications';
    }
}
