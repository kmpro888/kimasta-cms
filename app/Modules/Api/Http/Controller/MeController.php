<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Api\Http\ApiController;
use CMS\Modules\Api\Resources\ContentResource;
use CMS\Modules\Api\Resources\ContentSummaryResource;
use CMS\Modules\Api\Resources\WorkflowLogResource;
use CMS\Modules\Api\Validation\Validator;
use CMS\Modules\Contribution\Contracts\ContributorServiceInterface;
use CMS\Modules\Contribution\Exceptions\ContentNotEditableException;
use CMS\Modules\Contribution\Exceptions\NotYourContentException;
use CMS\Modules\Editorial\Exceptions\IllegalTransitionException;
use CMS\Modules\Editorial\Exceptions\WorkflowPermissionDeniedException;

/**
 * The contributor's own workspace: `/api/v1/me/...`.
 *
 * Every endpoint is scoped to the authenticated user, so an author never has to pass an
 * author id — and cannot pass someone else's. The rules live in
 * `ContributorServiceInterface`; this controller only shapes requests and responses.
 *
 * There is no publish endpoint here on purpose. Publishing is an editorial action and
 * requires a capability a contributor does not hold; adding it here would be exactly the
 * "separate publishing logic" the task pack forbids.
 */
final class MeController extends ApiController
{
    public function __construct(
        private readonly ContributorServiceInterface $contributors,
        private readonly ContentResource $contentResource,
        private readonly ContentSummaryResource $summaryResource,
        private readonly WorkflowLogResource $workflowLogResource,
    ) {
    }

    /**
     * GET /me/content — my editable work.
     */
    public function drafts(Request $request): Response
    {
        $actor = $this->requireAuthentication($request);
        $siteId = $this->siteId($request);

        [$limit, $offset] = $this->paging($request);

        $items = $this->contributors->myDrafts($siteId, (int) $actor->id(), $limit, $offset);

        return $this->ok(array_map(fn ($item): array => $this->summaryResource->toArray($item), $items), [
            'limit' => $limit,
            'offset' => $offset,
            'editable_statuses' => $this->contributors->editableStatuses(),
        ]);
    }

    /**
     * GET /me/content/published — my published work.
     */
    public function published(Request $request): Response
    {
        $actor = $this->requireAuthentication($request);
        $siteId = $this->siteId($request);

        [$limit, $offset] = $this->paging($request);

        $items = $this->contributors->myPublished($siteId, (int) $actor->id(), $limit, $offset);

        return $this->ok(array_map(fn ($item): array => $this->summaryResource->toArray($item), $items), [
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * POST /me/content — create a draft owned by me.
     */
    public function store(Request $request): Response
    {
        $actor = $this->requireAuthentication($request);
        $siteId = $this->siteId($request);

        $data = $request->jsonBody();
        $validator = Validator::make($data)
            ->required('title')
            ->string('title', 255)
            ->string('excerpt', 1000)
            ->oneOf('type', ['post', 'page'])
            ->integer('featured_media_id', 1);

        $this->ensureValid($validator);

        $payload = [
            'title' => (string) $data['title'],
            'type' => isset($data['type']) && is_string($data['type']) ? $data['type'] : 'post',
        ];

        foreach (['excerpt', 'content_json', 'featured_media_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        $this->assertBlocksShape($payload);

        $content = $this->contributors->createDraft($siteId, (int) $actor->id(), $payload);

        return $this->ok($this->contentResource->toArray($content), [], 201);
    }

    /**
     * PATCH /me/content/{id} — edit my own draft.
     */
    public function update(Request $request): Response
    {
        $actor = $this->requireAuthentication($request);
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        $data = $request->jsonBody();
        $validator = Validator::make($data)
            ->string('title', 255)
            ->string('excerpt', 1000)
            ->integer('featured_media_id', 1);

        $this->ensureValid($validator);

        $payload = [];

        foreach (['title', 'excerpt', 'content_json', 'featured_media_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if ($payload === []) {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['body' => ['No updatable field was supplied.']],
            ]);
        }

        $this->assertBlocksShape($payload);

        try {
            $content = $this->contributors->updateOwn($siteId, (int) $actor->id(), $id, $payload);
        } catch (NotYourContentException) {
            throw HttpException::notFound();
        } catch (ContentNotEditableException $exception) {
            throw new HttpException(409, $exception->getMessage(), 'CONTENT_NOT_EDITABLE');
        }

        return $this->ok($this->contentResource->toArray($content));
    }

    /**
     * POST /me/content/{id}/submit — submit or resubmit.
     */
    public function submit(Request $request): Response
    {
        $actor = $this->requireAuthentication($request);
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        $data = $request->jsonBody();
        $note = isset($data['note']) && is_string($data['note']) ? $data['note'] : null;

        try {
            $content = $this->contributors->submit($siteId, (int) $actor->id(), $id, $note);
        } catch (NotYourContentException) {
            throw HttpException::notFound();
        } catch (IllegalTransitionException $exception) {
            throw new HttpException(409, $exception->getMessage(), 'INVALID_STATE_TRANSITION');
        } catch (WorkflowPermissionDeniedException) {
            // A contributor without `workflow.submit` cannot push work into review.
            throw HttpException::forbidden('You are not allowed to submit this content.');
        }

        return $this->ok($this->contentResource->toArray($content));
    }

    /**
     * GET /me/content/{id}/history — my piece's editorial history.
     */
    public function history(Request $request): Response
    {
        $actor = $this->requireAuthentication($request);
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        try {
            $logs = $this->contributors->history($siteId, (int) $actor->id(), $id);
        } catch (NotYourContentException) {
            throw HttpException::notFound();
        }

        return $this->ok(array_map(
            fn ($log): array => $this->workflowLogResource->toArray($log),
            $logs
        ));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function paging(Request $request): array
    {
        $limit = (int) ($request->queryParam('limit') ?? 50);
        $offset = (int) ($request->queryParam('offset') ?? 0);

        return [max(1, min(100, $limit)), max(0, $offset)];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertBlocksShape(array $payload): void
    {
        if (array_key_exists('content_json', $payload) && !is_array($payload['content_json'])) {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['content_json' => ['This field must be an array of blocks.']],
            ]);
        }
    }
}
