<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Http\AdminController;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Admin\Support\BlockFormCompiler;
use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Entity\Content;
use CMS\Modules\Content\Exceptions\InvalidContentDataException;
use CMS\Modules\Contribution\Contracts\ContributorServiceInterface;
use CMS\Modules\Contribution\Exceptions\ContentNotEditableException;
use CMS\Modules\Editorial\Contracts\EditorialWorkflowInterface;
use CMS\Modules\Editorial\Exceptions\IllegalTransitionException;
use CMS\Modules\Editorial\Exceptions\WorkflowPermissionDeniedException;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Seo\Contracts\SeoServiceInterface;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * The content screens: list, editor, review.
 *
 * The controller holds no rules. It turns a form into a service call and a service exception
 * back into a message, which is why the API and these forms cannot disagree about what a
 * valid article is.
 *
 * Which buttons a screen offers is computed from the same workflow the server enforces
 * (`allowedTransitions()` + `requiredCapability()`), so the interface cannot promise an action
 * the server will refuse.
 */
final class ContentController extends AdminController
{
    public function __construct(
        AdminView $view,
        SessionInterface $session,
        CsrfTokenManagerInterface $csrf,
        ActorProviderInterface $actors,
        private readonly ContentServiceInterface $contents,
        private readonly ContentQueryServiceInterface $query,
        private readonly ContributorServiceInterface $contributor,
        private readonly EditorialWorkflowInterface $workflow,
        private readonly TaxonomyServiceInterface $taxonomy,
        private readonly SeoServiceInterface $seo,
        private readonly MediaServiceInterface $media,
        private readonly BlockFormCompiler $blocks,
    ) {
        parent::__construct($view, $session, $csrf, $actors);
    }

    // -------------------------------------------------------------------- list

    public function index(Request $request): Response
    {
        $actor = $this->requireCapability($request, 'content.read');
        $siteId = $this->siteId($request);

        $status = $this->stringParam($request, 'status');
        $search = trim($this->stringParam($request, 'q'));

        $criteria = ['limit' => 20, 'order' => 'newest'];

        if ($status !== '') {
            $criteria['statuses'] = [$status];
        }

        if ($search !== '') {
            $criteria['search'] = $search;
        }

        // Someone who cannot manage everyone's content sees only their own. This is a
        // narrower view, not a different page: the same template renders both.
        $mineOnly = !$actor->can('content.update');

        if ($mineOnly) {
            $criteria['author_id'] = (int) $actor->id();
        }

        return $this->html($request, 'content/index', 'Content', [
            'result' => $this->query->paginate($siteId, $criteria),
            'status' => $status,
            'search' => $search,
            'mineOnly' => $mineOnly,
            'canCreate' => $actor->can('content.create'),
        ]);
    }

    // ------------------------------------------------------------------ editor

    public function create(Request $request): Response
    {
        $this->requireCapability($request, 'content.create');

        return $this->form($request, null, [
            'title' => '',
            'slug' => '',
            'excerpt' => '',
            'featured_media_id' => 0,
            'rows' => [],
            'terms' => [],
            'seo' => ['meta_title' => '', 'meta_description' => ''],
        ], [], '/admin/content');
    }

    public function store(Request $request): Response
    {
        $actor = $this->requireCapability($request, 'content.create');
        $siteId = $this->siteId($request);

        $values = $this->values($request, null);

        try {
            $created = $this->contributor->createDraft($siteId, (int) $actor->id(), $this->attributes($values));
        } catch (InvalidContentDataException $exception) {
            return $this->form($request, null, $values, ['title' => [$exception->getMessage()]], '/admin/content', 422);
        }

        $this->flash('success', 'Draft created.');

        return $this->redirect('/admin/content/' . (int) $created->id);
    }

    public function edit(Request $request): Response
    {
        $content = $this->findContent($request);
        $actor = $this->requireCapability($request, 'content.read');

        $editable = $this->contributor->isEditable($this->siteId($request), (int) $actor->id(), (int) $content->id)
            || ($actor->can('content.update') && !$content->isPublished());

        $meta = $this->seo->metaFor((int) $content->id, $this->siteId($request));

        return $this->form($request, $content, [
            'title' => $content->title,
            'slug' => $content->slug,
            'excerpt' => (string) ($content->excerpt ?? ''),
            'featured_media_id' => (int) ($content->featuredMediaId ?? 0),
            'rows' => $this->blocks->toForm($content->blocks()),
            'terms' => $this->termIdsFor($content, $this->siteId($request)),
            'seo' => [
                'meta_title' => $meta->title,
                'meta_description' => $meta->description,
            ],
        ], [], '/admin/content/' . (int) $content->id, 200, $editable);
    }

    public function update(Request $request): Response
    {
        $content = $this->findContent($request);
        $actor = $this->requireCapability($request, 'content.read');
        $siteId = $this->siteId($request);
        $id = (int) $content->id;

        $values = $this->values($request, $content);

        try {
            if ($this->contributor->isEditable($siteId, (int) $actor->id(), $id)) {
                $this->contributor->updateOwn($siteId, (int) $actor->id(), $id, $this->attributes($values));
            } elseif ($actor->can('content.update')) {
                $this->contents->update($id, $siteId, $this->attributes($values));
            } else {
                throw HttpException::forbidden('You cannot edit this article.');
            }
        } catch (ContentNotEditableException) {
            return $this->form($request, $content, $values, [
                'title' => ['This article can no longer be edited. Ask for a revision.'],
            ], '/admin/content/' . $id, 409, false);
        } catch (InvalidContentDataException $exception) {
            return $this->form($request, $content, $values, [
                'title' => [$exception->getMessage()],
            ], '/admin/content/' . $id, 422);
        }

        $this->flash('success', 'Saved.');

        return $this->redirect('/admin/content/' . $id);
    }

    // ----------------------------------------------------------------- workflow

    public function submit(Request $request): Response
    {
        $content = $this->findContent($request);
        $actor = $this->requireCapability($request, 'workflow.submit');

        try {
            $this->contributor->submit(
                $this->siteId($request),
                (int) $actor->id(),
                (int) $content->id,
                $this->stringParam($request, 'note', ''),
            );
        } catch (ContentNotEditableException) {
            $this->flash('error', 'That article cannot be submitted right now.');

            return $this->redirect('/admin/content/' . (int) $content->id);
        }

        $this->flash('success', 'Sent for review.');

        return $this->redirect('/admin/content/' . (int) $content->id);
    }

    /**
     * One workflow step.
     *
     * The target status is checked against `allowedTransitions()` before anything is written,
     * so an illegal move is a message rather than a corrupt state.
     */
    public function transition(Request $request): Response
    {
        $content = $this->findContent($request);
        $actor = $this->requireCapability($request, 'content.read');
        $target = $this->stringParam($request, 'to');
        $id = (int) $content->id;

        if (!$this->workflow->canTransition($content->status, $target)) {
            $this->flash('error', 'That step is not available from ' . $content->status . '.');

            return $this->redirect('/admin/content/' . $id);
        }

        try {
            $this->workflow->transition(
                $id,
                $this->siteId($request),
                $actor,
                $target,
                $this->stringParam($request, 'note', ''),
            );
        } catch (WorkflowPermissionDeniedException) {
            $this->flash('error', 'You do not have permission for that step.');

            return $this->redirect('/admin/content/' . $id);
        } catch (IllegalTransitionException) {
            $this->flash('error', 'That step is not allowed.');

            return $this->redirect('/admin/content/' . $id);
        }

        $this->flash('success', 'Moved to ' . $target . '.');

        return $this->redirect('/admin/content/' . $id);
    }

    public function review(Request $request): Response
    {
        $content = $this->findContent($request);
        $actor = $this->requireCapability($request, 'workflow.review');
        $siteId = $this->siteId($request);

        $available = [];

        foreach ($this->workflow->allowedTransitions($content->status) as $target) {
            if ($actor->can($this->workflow->requiredCapability($content->status, $target))) {
                $available[] = $target;
            }
        }

        return $this->html($request, 'content/review', 'Review: ' . $content->title, [
            'content' => $content,
            'blocks' => $content->blocks(),
            'available' => $available,
            'history' => $this->workflow->history((int) $content->id, $siteId),
        ]);
    }

    // -------------------------------------------------------------- side panels

    public function terms(Request $request): Response
    {
        $content = $this->findContent($request);
        $this->requireCapability($request, 'content.read');

        $raw = $request->input('term_ids', []);

        $ids = is_array($raw)
            ? array_values(array_filter(array_map('intval', $raw), static fn (int $id): bool => $id > 0))
            : [];

        $this->taxonomy->sync((int) $content->id, $this->siteId($request), $ids);

        $this->flash('success', 'Categories updated.');

        return $this->redirect('/admin/content/' . (int) $content->id);
    }

    public function seo(Request $request): Response
    {
        $content = $this->findContent($request);
        $this->requireCapability($request, 'content.read');

        $this->seo->store((int) $content->id, $this->siteId($request), [
            'meta_title' => $this->stringParam($request, 'meta_title', ''),
            'meta_description' => $this->stringParam($request, 'meta_description', ''),
        ]);

        $this->flash('success', 'SEO updated.');

        return $this->redirect('/admin/content/' . (int) $content->id);
    }

    public function delete(Request $request): Response
    {
        $content = $this->findContent($request);
        $this->requireCapability($request, 'content.delete');

        $this->contents->trash((int) $content->id, $this->siteId($request));

        $this->flash('success', 'Moved to trash.');

        return $this->redirect('/admin/content');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Render the editor with everything its panels need.
     *
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     */
    private function form(
        Request $request,
        ?Content $content,
        array $values,
        array $errors,
        string $action,
        int $status = 200,
        bool $editable = true,
    ): Response {
        $actor = $this->actor($request);
        $siteId = $this->siteId($request);

        $available = [];

        if ($content !== null) {
            foreach ($this->workflow->allowedTransitions($content->status) as $target) {
                if ($actor->can($this->workflow->requiredCapability($content->status, $target))) {
                    $available[] = $target;
                }
            }
        }

        return $this->html($request, 'content/form', $content === null ? 'New article' : $content->title, [
            'content' => $content,
            'values' => $values,
            'errors' => $errors,
            'action' => $action,
            'editable' => $editable,
            'available' => $available,
            'canSubmit' => $content !== null
                && $actor->can('workflow.submit')
                && in_array('submitted', $available, true),
            'types' => $this->blocks->types(),
            'categories' => $this->taxonomy->listTerms($siteId, 'category'),
        ], $status);
    }

    /**
     * Read the submitted editor fields into one shape.
     *
     * @return array<string, mixed>
     */
    private function values(Request $request, ?Content $content): array
    {
        // Parsed once and reused for both the service call and the re-rendered form: parsing
        // twice risks the form showing something different from what was saved.
        $blocks = $this->blocks->fromForm($request->all());

        return [
            'title' => trim($this->stringParam($request, 'title')),
            'slug' => trim($this->stringParam($request, 'slug')),
            'excerpt' => trim($this->stringParam($request, 'excerpt')),
            'featured_media_id' => $this->intParam($request, 'featured_media_id'),
            'blocks' => $blocks,
            'rows' => $this->blocks->toForm($blocks),
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function attributes(array $values): array
    {
        $attributes = [
            'title' => $values['title'],
            'content_json' => $values['blocks'],
        ];

        foreach (['slug', 'excerpt'] as $key) {
            if (($values[$key] ?? '') !== '') {
                $attributes[$key] = $values[$key];
            }
        }

        if (($values['featured_media_id'] ?? 0) > 0) {
            $attributes['featured_media_id'] = (int) $values['featured_media_id'];
        }

        return $attributes;
    }

    /**
     * @return list<int>
     */
    private function termIdsFor(Content $content, int $siteId): array
    {
        $ids = [];

        $grouped = $this->taxonomy->termsForContents($siteId, [(int) $content->id]);

        foreach ($grouped[(int) $content->id] ?? [] as $term) {
            $ids[] = (int) $term->id;
        }

        return $ids;
    }

    /**
     * Fetch the article named in the route, mapping a missing or foreign row to 404.
     */
    private function findContent(Request $request): Content
    {
        $id = $this->intParam($request, 'id');

        try {
            return $this->contents->get($id, $this->siteId($request));
        } catch (\CMS\Modules\Content\Exceptions\ContentNotFoundException) {
            throw \CMS\Core\Http\HttpException::notFound();
        }
    }
}
