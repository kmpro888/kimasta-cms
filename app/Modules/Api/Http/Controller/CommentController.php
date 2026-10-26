<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Api\Http\ApiController;
use CMS\Modules\Api\Validation\Validator;
use CMS\Modules\Comment\Contracts\CommentServiceInterface;
use CMS\Modules\Comment\Entity\Comment;
use CMS\Modules\Comment\Exceptions\CommentDepthExceededException;
use CMS\Modules\Comment\Exceptions\CommentNotFoundException;
use CMS\Modules\Comment\Exceptions\CommentsClosedException;

/**
 * Comment endpoints.
 *
 * Submission requires a session: the task pack is explicit. Moderation requires
 * `comment.moderate`.
 *
 * Visibility rule: an anonymous caller sees only the approved thread. Moderation
 * states are never inferred from a 404 — a pending comment simply does not exist for
 * them.
 */
final class CommentController extends ApiController
{
    public function __construct(private readonly CommentServiceInterface $comments)
    {
    }

    /**
     * GET /content/{id}/comments
     */
    public function index(Request $request): Response
    {
        $siteId = $this->siteId($request);
        $contentId = $this->intParam($request, 'id');

        $moderator = $this->actor($request)->can('comment.moderate');
        $status = $request->queryParam('status');

        if ($moderator && is_string($status) && $status !== '') {
            // A moderator can ask for a specific moderation state.
            $list = $this->comments->listForSite($siteId, $status, 200, 0);
            $list = array_values(array_filter($list, static fn (Comment $c): bool => $c->contentId === $contentId));

            return $this->ok(array_map(fn (Comment $c): array => $this->project($c), $list));
        }

        $thread = $this->comments->thread($siteId, $contentId);

        return $this->ok(array_map(fn (Comment $c): array => $this->project($c), $thread));
    }

    /**
     * POST /content/{id}/comments
     */
    public function store(Request $request): Response
    {
        $actor = $this->requireAuthentication($request);
        $siteId = $this->siteId($request);
        $contentId = $this->intParam($request, 'id');

        $data = $request->jsonBody();
        $validator = Validator::make($data)
            ->required('body')
            ->string('body', 5000)
            ->integer('parent_id', 1);

        $this->ensureValid($validator);

        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

        try {
            $comment = $this->comments->submit(
                $siteId,
                $contentId,
                (int) $actor->id(),
                (string) $data['body'],
                $parentId,
            );
        } catch (CommentsClosedException) {
            throw new HttpException(409, 'Comments are closed for this content.', 'COMMENTS_CLOSED');
        } catch (CommentDepthExceededException) {
            throw new HttpException(
                409,
                'This reply would exceed the maximum thread depth.',
                'COMMENT_DEPTH_EXCEEDED'
            );
        } catch (CommentNotFoundException) {
            throw HttpException::notFound();
        } catch (\InvalidArgumentException $exception) {
            throw new HttpException(422, $exception->getMessage(), 'VALIDATION_ERROR', [
                'fields' => ['body' => [$exception->getMessage()]],
            ]);
        }

        return $this->ok($this->project($comment), [], 201);
    }

    /**
     * POST /comments/{id}/approve
     */
    public function approve(Request $request): Response
    {
        return $this->moderate($request, 'approve');
    }

    /**
     * POST /comments/{id}/spam
     */
    public function spam(Request $request): Response
    {
        return $this->moderate($request, 'markSpam');
    }

    /**
     * POST /comments/{id}/trash
     */
    public function trash(Request $request): Response
    {
        return $this->moderate($request, 'trash');
    }

    /**
     * DELETE /comments/{id}
     */
    public function destroy(Request $request): Response
    {
        $this->requireCapability($request, 'comment.moderate');
        $siteId = $this->siteId($request);
        $commentId = $this->intParam($request, 'id');

        try {
            $this->comments->delete($commentId, $siteId);
        } catch (CommentNotFoundException) {
            throw HttpException::notFound();
        }

        return new Response('', 204);
    }

    /**
     * GET /comments - moderation queue.
     */
    public function queue(Request $request): Response
    {
        $this->requireCapability($request, 'comment.moderate');
        $siteId = $this->siteId($request);

        $status = $request->queryParam('status');
        $status = is_string($status) && $status !== '' ? $status : Comment::STATUS_PENDING;

        $page = max(1, (int) ($request->queryParam('page') ?? 1));
        $perPage = max(1, min(100, (int) ($request->queryParam('per_page') ?? 20)));

        $items = $this->comments->listForSite($siteId, $status, $perPage, ($page - 1) * $perPage);

        return $this->ok(array_map(fn (Comment $c): array => $this->project($c), $items), [
            'page' => $page,
            'per_page' => $perPage,
            'status' => $status,
            'total' => $this->comments->countForSite($siteId, $status),
        ]);
    }

    private function moderate(Request $request, string $action): Response
    {
        $this->requireCapability($request, 'comment.moderate');
        $siteId = $this->siteId($request);
        $commentId = $this->intParam($request, 'id');

        try {
            $comment = $this->comments->{$action}($commentId, $siteId);
        } catch (CommentNotFoundException) {
            throw HttpException::notFound();
        }

        return $this->ok($this->project($comment));
    }

    /**
     * @return array<string, mixed>
     */
    private function project(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'content_id' => $comment->contentId,
            'parent_id' => $comment->parentId,
            'author_name' => $comment->authorName,
            'body' => $comment->body,
            'status' => $comment->status,
            'depth' => $comment->depth,
            'created_at' => $comment->createdAt->format(DATE_ATOM),
        ];
    }
}
