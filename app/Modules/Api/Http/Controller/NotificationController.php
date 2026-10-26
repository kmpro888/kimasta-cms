<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Api\Http\ApiController;
use CMS\Modules\Api\Validation\Validator;
use CMS\Modules\Notification\Contracts\NotificationServiceInterface;
use CMS\Modules\Notification\Entity\Notification;

/**
 * Notification inbox and channel preferences.
 *
 * The inbox is always scoped to the authenticated user, so an id cannot be used to read or
 * mark someone else's notification: the repository puts `user_id` in the WHERE clause and a
 * foreign id simply matches nothing.
 *
 * Preferences require `settings.update`; reading them only needs authentication, because a
 * user checking their own channels is not an administrative act.
 */
final class NotificationController extends ApiController
{
    public function __construct(private readonly NotificationServiceInterface $notifications)
    {
    }

    /**
     * GET /me/notifications
     */
    public function index(Request $request): Response
    {
        $actor = $this->requireAuthentication($request);
        $siteId = $this->siteId($request);

        $unreadOnly = $request->queryParam('unread') === '1';
        $limit = (int) ($request->queryParam('limit') ?? 50);

        $items = $this->notifications->listForUser($siteId, (int) $actor->id(), $unreadOnly, $limit);

        return $this->ok(
            array_map(fn (Notification $n): array => $this->project($n), $items),
            ['unread_count' => $this->notifications->unreadCount($siteId, (int) $actor->id())]
        );
    }

    /**
     * POST /me/notifications/{id}/read
     */
    public function markRead(Request $request): Response
    {
        $actor = $this->requireAuthentication($request);
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        if (!$this->notifications->markRead($id, $siteId, (int) $actor->id())) {
            // Either it does not exist, or it belongs to someone else. Both are a 404 so the
            // response cannot confirm that another user has a notification with this id.
            throw HttpException::notFound();
        }

        return $this->ok([
            'id' => $id,
            'unread_count' => $this->notifications->unreadCount($siteId, (int) $actor->id()),
        ]);
    }

    /**
     * POST /me/notifications/read-all
     */
    public function markAllRead(Request $request): Response
    {
        $actor = $this->requireAuthentication($request);
        $siteId = $this->siteId($request);

        $updated = $this->notifications->markAllRead($siteId, (int) $actor->id());

        return $this->ok(['updated' => $updated, 'unread_count' => 0]);
    }

    /**
     * GET /notifications/preferences
     */
    public function preferences(Request $request): Response
    {
        $this->requireAuthentication($request);
        $siteId = $this->siteId($request);

        return $this->ok($this->notifications->preferences($siteId));
    }

    /**
     * PUT /notifications/preferences
     */
    public function updatePreferences(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');
        $siteId = $this->siteId($request);

        $data = $request->jsonBody();
        $validator = Validator::make($data)->required('preferences');

        $this->ensureValid($validator);

        $preferences = $data['preferences'];

        if (!is_array($preferences)) {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['preferences' => ['This field must be an object keyed by event.']],
            ]);
        }

        foreach ($preferences as $event => $channels) {
            if (!is_string($event) || !is_array($channels)) {
                throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                    'fields' => ['preferences' => ['Each entry must be event => {channel: bool}.']],
                ]);
            }

            foreach ($channels as $channel => $enabled) {
                try {
                    $this->notifications->setPreference(
                        $siteId,
                        $event,
                        (string) $channel,
                        $enabled === true || $enabled === 1 || $enabled === '1' || $enabled === 'true',
                    );
                } catch (\InvalidArgumentException $exception) {
                    throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                        'fields' => ['preferences' => [$exception->getMessage()]],
                    ]);
                }
            }
        }

        return $this->ok($this->notifications->preferences($siteId));
    }

    /**
     * @return array<string, mixed>
     */
    private function project(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'event' => $notification->event,
            'title' => $notification->title,
            'body' => $notification->body,
            'content_id' => $notification->contentId,
            'read' => $notification->isRead(),
            'read_at' => $notification->readAt?->format(DATE_ATOM),
            'created_at' => $notification->createdAt->format(DATE_ATOM),
        ];
    }
}
