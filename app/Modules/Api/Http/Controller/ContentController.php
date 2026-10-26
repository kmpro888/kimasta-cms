<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Api\Http\ApiController;
use CMS\Modules\Api\Resources\ContentResource;
use CMS\Modules\Api\Resources\ContentSummaryResource;
use CMS\Modules\Api\Resources\RevisionResource;
use CMS\Modules\Api\Resources\WorkflowLogResource;
use CMS\Modules\Api\Validation\Validator;
use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Contracts\ContentTypeRegistryInterface;
use CMS\Modules\Content\Exceptions\ContentNotFoundException;
use CMS\Modules\Editorial\Contracts\EditorialWorkflowInterface;
use CMS\Modules\Revision\Contracts\RevisionServiceInterface;
use CMS\Modules\Search\Contracts\SearchServiceInterface;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;

/**
 * Content endpoints.
 *
 * Every handler follows the same four steps: validate shape, require capability,
 * call one service, project through a resource. There is no business logic here -
 * the rules live in `ContentService`, `EditorialWorkflow` and `RevisionService`.
 *
 * Cross-site access is not special-cased: the services already scope by site, so an
 * id from another tenant raises `ContentNotFoundException`, which becomes a 404 and
 * reveals nothing.
 */
final class ContentController extends ApiController
{
    private const MAX_PER_PAGE = 100;

    /**
     * Capability meaning "may see content the public cannot": drafts, scheduled
     * items and trashed rows.
     *
     * There is no separate `content.read_private` capability in the catalog.
     * `content.read` is the admin-interface read grant, which is exactly this.
     */
    private const CAP_READ_PRIVATE = 'content.read';

    public function __construct(
        private readonly ContentServiceInterface $contents,
        private readonly ContentQueryServiceInterface $query,
        private readonly ContentTypeRegistryInterface $types,
        private readonly EditorialWorkflowInterface $workflow,
        private readonly RevisionServiceInterface $revisions,
        private readonly SearchServiceInterface $search,
        private readonly TaxonomyServiceInterface $taxonomy,
        private readonly ContentResource $contentResource,
        private readonly ContentSummaryResource $summaryResource,
        private readonly RevisionResource $revisionResource,
        private readonly WorkflowLogResource $workflowLogResource,
    ) {
    }

    /**
     * GET /content - paginated listing.
     */
    public function index(Request $request): Response
    {
        return $this->listContent($request, null);
    }

    /**
     * GET /posts - listing pinned to the `post` type.
     *
     * A dedicated action rather than an alias: `/posts` must not be able to return
     * pages even if the caller passes `?type=page`.
     */
    public function posts(Request $request): Response
    {
        return $this->listContent($request, 'post');
    }

    /**
     * GET /pages - listing pinned to the `page` type.
     */
    public function pages(Request $request): Response
    {
        return $this->listContent($request, 'page');
    }

    /**
     * Shared listing implementation.
     *
     * When `$pinnedType` is set the type is fixed by the route and any `type` query
     * parameter is ignored.
     */
    private function listContent(Request $request, ?string $pinnedType): Response
    {
        $siteId = $this->siteId($request);
        $actor = $this->actor($request);

        $page = max(1, (int) ($request->queryParam('page') ?? 1));
        $perPage = (int) ($request->queryParam('per_page') ?? 20);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $criteria = [
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ];

        if ($pinnedType !== null) {
            $criteria['type'] = $pinnedType;
        } else {
            $type = $request->queryParam('type');

            if (is_string($type) && $type !== '') {
                $criteria['type'] = $type;
            }
        }

        $status = $request->queryParam('status');

        if (is_string($status) && $status !== '') {
            $criteria['status'] = $status;
        }

        // An anonymous caller only ever sees published content, whatever it asks
        // for. Filtering after the fact would still leak the total count.
        $criteria['exclude_trash'] = true;

        if (!$actor->can(self::CAP_READ_PRIVATE)) {
            $criteria['published_only'] = true;
        }

        $result = $this->query->paginate($siteId, $criteria);

        $data = array_map(
            fn ($item): array => $this->summaryResource->toArray($item),
            $result['items']
        );

        return $this->ok($data, $this->paginationMeta(
            $page,
            $perPage,
            $result['total'],
            $result['total_pages'],
        ));
    }

    /**
     * GET /search?q=...
     *
     * Delegates to the Search module, which owns the strategy decision: FULLTEXT on
     * `ft_contents_search` when the index exists, an indexed-LIKE fallback when it
     * does not. Results are ordered by publish date; there is no relevance ranking,
     * and the response says which strategy ran so an operator can tell why it is slow.
     */
    public function search(Request $request): Response
    {
        $siteId = $this->siteId($request);
        $actor = $this->actor($request);

        $query = $request->queryParam('q');
        $needle = is_string($query) ? trim($query) : '';

        if ($needle === '') {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['q' => ['A search term is required.']],
            ]);
        }

        $page = max(1, (int) ($request->queryParam('page') ?? 1));
        $perPage = (int) ($request->queryParam('per_page') ?? 20);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $type = $request->queryParam('type');

        $result = $this->search->search($siteId, $needle, [
            'page' => $page,
            'limit' => $perPage,
            'type' => is_string($type) && $type !== '' ? $type : null,
            // Drafts are only included for a caller who may read private content.
            'include_unpublished' => $actor->can(self::CAP_READ_PRIVATE),
        ]);

        $data = array_map(
            fn ($item): array => $this->summaryResource->toArray($item),
            $result['items']
        );

        return $this->ok($data, $this->paginationMeta(
            $page,
            $perPage,
            $result['total'],
            $result['total_pages'],
        ) + ['strategy' => $result['strategy']]);
    }

    /**
     * GET /content/{id}
     */
    public function show(Request $request): Response
    {
        $siteId = $this->siteId($request);
        $actor = $this->actor($request);
        $id = $this->intParam($request, 'id');

        $content = $this->getContent($id, $siteId);

        // A non-public row is a 404 for a caller who cannot read private content.
        if (!$content->isPubliclyVisible() && !$actor->can(self::CAP_READ_PRIVATE)) {
            throw HttpException::notFound();
        }

        return $this->ok($this->contentResource->toArray($content));
    }

    /**
     * POST /content
     */
    public function store(Request $request): Response
    {
        $this->requireCapability($request, 'content.create');
        $siteId = $this->siteId($request);
        $actor = $this->actor($request);

        $data = $request->jsonBody();
        $validator = Validator::make($data)
            ->required('title')
            ->string('title', 255)
            ->string('excerpt', 1000)
            ->string('slug', 255)
            ->oneOf('type', ['post', 'page'])
            ->integer('featured_media_id', 1);

        $this->ensureValid($validator);

        $payload = [
            'title' => (string) $data['title'],
            'type' => isset($data['type']) && is_string($data['type']) ? $data['type'] : 'post',
            'author_id' => (int) $actor->id(),
        ];

        foreach (['excerpt', 'slug', 'content_json'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if (array_key_exists('content_json', $payload) && !is_array($payload['content_json'])) {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['content_json' => ['This field must be an array of blocks.']],
            ]);
        }

        if (isset($data['featured_media_id'])) {
            $payload['featured_media_id'] = (int) $data['featured_media_id'];
        }

        $content = $this->contents->create($siteId, $payload);

        return $this->ok($this->contentResource->toArray($content), [], 201);
    }

    /**
     * PATCH /content/{id}
     */
    public function update(Request $request): Response
    {
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        $existing = $this->getContent($id, $siteId);

        // An authenticated author may edit their own row. Editing someone else's
        // row, or any row as a non-author, needs the broader capability.
        $actor = $this->requireAuthentication($request);
        $isOwn = (int) $existing->authorId === (int) $actor->id();

        if (!$isOwn && !$actor->can('content.update')) {
            throw HttpException::forbidden('You may only edit your own content.');
        }

        $data = $request->jsonBody();
        $validator = Validator::make($data)
            ->string('title', 255)
            ->string('excerpt', 1000)
            ->string('slug', 255)
            ->integer('featured_media_id', 1);

        $this->ensureValid($validator);

        $payload = [];

        foreach (['title', 'excerpt', 'slug', 'content_json'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if (array_key_exists('content_json', $payload) && !is_array($payload['content_json'])) {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['content_json' => ['This field must be an array of blocks.']],
            ]);
        }

        if (array_key_exists('featured_media_id', $data)) {
            $payload['featured_media_id'] = $data['featured_media_id'] === null ? null : (int) $data['featured_media_id'];
        }

        if ($payload === []) {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['body' => ['No updatable field was supplied.']],
            ]);
        }

        $content = $this->contents->update($id, $siteId, $payload);

        return $this->ok($this->contentResource->toArray($content));
    }

    /**
     * DELETE /content/{id} - moves to trash.
     */
    public function destroy(Request $request): Response
    {
        $this->requireCapability($request, 'content.delete');
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        $this->contents->trash($id, $siteId);

        return new Response('', 204);
    }

    /**
     * POST /content/{id}/transitions - editorial workflow.
     */
    public function transition(Request $request): Response
    {
        $siteId = $this->siteId($request);
        $actor = $this->requireAuthentication($request);
        $id = $this->intParam($request, 'id');

        // Confirms the row belongs to this tenant before the workflow is asked.
        $this->getContent($id, $siteId);

        $data = $request->jsonBody();
        $validator = Validator::make($data)->required('status')->string('status', 40)->string('note', 1000);

        $this->ensureValid($validator);

        $content = $this->workflow->transition(
            $id,
            $siteId,
            $actor,
            (string) $data['status'],
            isset($data['note']) && is_string($data['note']) ? $data['note'] : null,
        );

        return $this->ok($this->contentResource->toArray($content));
    }

    /**
     * GET /content/{id}/transitions - what is legal next.
     */
    public function transitions(Request $request): Response
    {
        // Editorial state is not public: revealing a draft's allowed transitions
        // would confirm that the draft exists.
        $this->requireCapability($request, self::CAP_READ_PRIVATE);
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        $content = $this->getContent($id, $siteId);

        return $this->ok([
            'current_status' => $content->status,
            'allowed' => $this->workflow->allowedTransitions($content->status),
        ]);
    }

    /**
     * GET /content/{id}/history - editorial log.
     */
    public function history(Request $request): Response
    {
        $this->requireCapability($request, self::CAP_READ_PRIVATE);
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        $this->getContent($id, $siteId);

        $logs = $this->workflow->history($id, $siteId);

        return $this->ok(array_map(fn ($log): array => $this->workflowLogResource->toArray($log), $logs));
    }

    /**
     * GET /content/{id}/revisions
     */
    public function revisions(Request $request): Response
    {
        $this->requireCapability($request, self::CAP_READ_PRIVATE);
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        $this->getContent($id, $siteId);

        $list = $this->revisions->listFor($id, $siteId);

        return $this->ok(array_map(fn ($revision): array => $this->revisionResource->toArray($revision), $list));
    }

    /**
     * POST /content/{id}/revisions - take a snapshot.
     */
    public function snapshot(Request $request): Response
    {
        $this->requireCapability($request, 'content.update');
        $siteId = $this->siteId($request);
        $actor = $this->actor($request);
        $id = $this->intParam($request, 'id');

        $this->getContent($id, $siteId);

        $revision = $this->revisions->snapshot($id, $siteId, (int) $actor->id());

        return $this->ok($this->revisionResource->toArray($revision), [], 201);
    }

    /**
     * POST /content/{id}/autosave
     *
     * An autosave updates the draft fields without touching status and without
     * emitting a publish event, so it cannot accidentally make work public.
     */
    public function autosave(Request $request): Response
    {
        $siteId = $this->siteId($request);
        $actor = $this->requireAuthentication($request);
        $id = $this->intParam($request, 'id');

        $content = $this->getContent($id, $siteId);

        if ((int) $content->authorId !== (int) $actor->id() && !$actor->can('content.update')) {
            throw HttpException::forbidden('You may only autosave your own content.');
        }

        $data = $request->jsonBody();
        $payload = [];

        foreach (['title', 'excerpt', 'content_json'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if ($payload === []) {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['body' => ['Nothing to autosave.']],
            ]);
        }

        if (array_key_exists('content_json', $payload) && !is_array($payload['content_json'])) {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['content_json' => ['This field must be an array of blocks.']],
            ]);
        }

        $updated = $this->contents->update($id, $siteId, $payload);

        return $this->ok([
            'id' => $updated->id,
            'updated_at' => $updated->updatedAt->format(DATE_ATOM),
        ]);
    }

    /**
     * GET /content/{id}/terms
     */
    public function terms(Request $request): Response
    {
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        $this->getContent($id, $siteId);

        $grouped = $this->taxonomy->termsForContents($siteId, [$id]);

        return $this->ok(array_map(
            static fn ($term): array => [
                'id' => $term->id,
                'name' => $term->name,
                'slug' => $term->slug,
                'taxonomy_id' => $term->taxonomyId,
            ],
            $grouped[$id] ?? []
        ));
    }

    /**
     * PUT /content/{id}/terms - replace the attached set.
     */
    public function syncTerms(Request $request): Response
    {
        // Authenticate before reading the row, so an anonymous caller cannot tell an
        // existing draft (401) from a missing one (404).
        $this->requireAuthentication($request);

        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        $content = $this->getContent($id, $siteId);

        // An author may classify their own piece: requiring `content.update` would mean a
        // contributor can write an article but never file it, which makes the contributor
        // workflow impossible through the API.
        $this->requireAuthorshipOrCapability($request, $content);

        $data = $request->jsonBody();
        $validator = Validator::make($data)->required('term_ids');

        $this->ensureValid($validator);

        if (!is_array($data['term_ids'])) {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['term_ids' => ['This field must be an array of ids.']],
            ]);
        }

        $this->taxonomy->sync($id, $siteId, array_map('intval', $data['term_ids']));

        return new Response('', 204);
    }

    /**
     * Allow the operation when the actor may edit any content, or owns this row.
     *
     * Ownership is not a capability: it is a property of the row. Encoding it as one would
     * need a capability per author.
     *
     * @throws HttpException 401 then 403
     */
    protected function requireAuthorshipOrCapability(
        Request $request,
        \CMS\Modules\Content\Entity\Content $content,
    ): void {
        $actor = $this->requireAuthentication($request);

        if ($actor->can('content.update')) {
            return;
        }

        if ((int) $content->authorId === (int) $actor->id()) {
            return;
        }

        throw HttpException::forbidden('You may only change your own content.');
    }

    /**
     * Fetch a content row, mapping a missing or foreign row to 404.
     */
    private function getContent(int $id, int $siteId): \CMS\Modules\Content\Entity\Content
    {
        try {
            return $this->contents->get($id, $siteId);
        } catch (ContentNotFoundException) {
            throw HttpException::notFound();
        }
    }
}


