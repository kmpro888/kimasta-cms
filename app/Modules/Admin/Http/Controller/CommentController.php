<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Http\AdminController;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Comment\Contracts\CommentServiceInterface;
use CMS\Modules\Comment\Entity\Comment;
use CMS\Modules\Comment\Exceptions\CommentNotFoundException;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Exceptions\ContentNotFoundException;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * Comment moderation.
 *
 * The queue is the reason comments have to start `pending`: a public-facing write endpoint that
 * publishes instantly is a spam target. This screen is where that decision gets acted on, so it
 * exists even though the public form does not yet.
 *
 * Article titles are resolved through the content service, one memoised lookup per article, so
 * the queue can name what a comment is about instead of showing a bare id.
 */
final class CommentController extends AdminController
{
    public function __construct(
        AdminView $view,
        SessionInterface $session,
        CsrfTokenManagerInterface $csrf,
        ActorProviderInterface $actors,
        private readonly CommentServiceInterface $comments,
        private readonly ContentServiceInterface $contents,
    ) {
        parent::__construct($view, $session, $csrf, $actors);
    }

    public function index(Request $request): Response
    {
        $this->requireCapability($request, 'comment.moderate');

        $siteId = $this->siteId($request);
        $status = $this->stringParam($request, 'status');
        $status = in_array($status, [Comment::STATUS_PENDING, Comment::STATUS_APPROVED, Comment::STATUS_SPAM, Comment::STATUS_TRASH], true)
            ? $status
            : Comment::STATUS_PENDING;

        $itemList = $this->comments->listForSite($siteId, $status, 50);

        return $this->html($request, 'comments/index', 'Comments', [
            'items' => $itemList,
            'status' => $status,
            'titles' => $this->titlesFor($itemList, $siteId),
            'counts' => [
                Comment::STATUS_PENDING => $this->comments->countForSite($siteId, Comment::STATUS_PENDING),
                Comment::STATUS_APPROVED => $this->comments->countForSite($siteId, Comment::STATUS_APPROVED),
                Comment::STATUS_SPAM => $this->comments->countForSite($siteId, Comment::STATUS_SPAM),
                Comment::STATUS_TRASH => $this->comments->countForSite($siteId, Comment::STATUS_TRASH),
            ],
        ]);
    }

    public function approve(Request $request): Response
    {
        return $this->act($request, 'Approved.', fn (int $id, int $siteId): Comment => $this->comments->approve($id, $siteId));
    }

    public function spam(Request $request): Response
    {
        return $this->act($request, 'Marked as spam.', fn (int $id, int $siteId): Comment => $this->comments->markSpam($id, $siteId));
    }

    public function trash(Request $request): Response
    {
        return $this->act($request, 'Moved to trash.', fn (int $id, int $siteId): Comment => $this->comments->trash($id, $siteId));
    }

    public function destroy(Request $request): Response
    {
        $this->requireCapability($request, 'comment.moderate');

        $id = $this->intParam($request, 'id');

        try {
            $this->comments->delete($id, $this->siteId($request));
        } catch (CommentNotFoundException) {
            throw HttpException::notFound();
        }

        $this->flash('success', 'Deleted.');

        return $this->redirect($this->backTo($request));
    }

    /**
     * POST 303 GET after every moderation action, so a refresh does not repeat it.
     *
     * @param callable(int, int): Comment $action
     */
    private function act(Request $request, string $message, callable $action): Response
    {
        $this->requireCapability($request, 'comment.moderate');

        $id = $this->intParam($request, 'id');

        try {
            $action($id, $this->siteId($request));
        } catch (CommentNotFoundException) {
            // A comment from another tenant behaves as absent, never as "forbidden".
            throw HttpException::notFound();
        }

        $this->flash('success', $message);

        return $this->redirect($this->backTo($request));
    }

    /**
     * Return to the filter the moderator was looking at.
     */
    private function backTo(Request $request): string
    {
        $status = $this->stringParam($request, 'status');

        return $status === '' ? '/admin/comments' : '/admin/comments?status=' . rawurlencode($status);
    }

    /**
     * Article titles for the comments in view.
     *
     * @param list<Comment> $comments
     *
     * @return array<int, string>
     */
    private function titlesFor(array $comments, int $siteId): array
    {
        $titles = [];

        foreach ($comments as $comment) {
            $contentId = (int) $comment->contentId;

            if (isset($titles[$contentId])) {
                continue;
            }

            try {
                // The content service memoises per request, so repeated ids cost nothing.
                $titles[$contentId] = $this->contents->get($contentId, $siteId)->title;
            } catch (ContentNotFoundException) {
                $titles[$contentId] = '(deleted article)';
            }
        }

        return $titles;
    }
}
