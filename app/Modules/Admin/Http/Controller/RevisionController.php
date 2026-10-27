<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Http\AdminController;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Exceptions\ContentNotFoundException;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Revision\Contracts\RevisionServiceInterface;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * The revision history of one article, and the button that puts an old version back.
 *
 * `RevisionService::restore()` existed with no route to reach it: revisions were written on every
 * save and could never be used. A history nobody can roll back is an audit log at best.
 *
 * Restoring takes a snapshot of the *current* state first, so a restore is itself undoable. Without
 * that, "restore" is a delete with a friendly name.
 */
final class RevisionController extends AdminController
{
    public function __construct(
        AdminView $view,
        SessionInterface $session,
        CsrfTokenManagerInterface $csrf,
        ActorProviderInterface $actors,
        private readonly RevisionServiceInterface $revisions,
        private readonly ContentServiceInterface $contents,
    ) {
        parent::__construct($view, $session, $csrf, $actors);
    }

    public function index(Request $request): Response
    {
        $this->requireCapability($request, 'content.update');

        $contentId = $this->intParam($request, 'id');
        $siteId = $this->siteId($request);

        try {
            $content = $this->contents->get($contentId, $siteId);
        } catch (ContentNotFoundException) {
            throw HttpException::notFound();
        }

        return $this->html($request, 'content/revisions', 'Revisions: ' . $content->title, [
            'content' => $content,
            'revisions' => $this->revisions->listFor($contentId, $siteId),
        ]);
    }

    /**
     * A revision against the one before it.
     *
     * The list screen says a revision exists; this screen says what changed. Without it the only
     * way to review a revision was to restore it and look.
     */
    public function diff(Request $request): Response
    {
        $this->requireCapability($request, 'content.update');

        $siteId = $this->siteId($request);
        $contentId = $this->intParam($request, 'id');
        $revisionId = $this->intParam($request, 'revision');

        try {
            $content = $this->contents->get($contentId, $siteId);
        } catch (ContentNotFoundException) {
            throw HttpException::notFound();
        }

        $revision = $this->revisions->get($revisionId, $siteId);

        if ((int) $revision->contentId !== $contentId) {
            throw HttpException::notFound();
        }

        return $this->html(
            $request,
            'content/revision-diff',
            'Compare revision ' . $revision->revisionNumber,
            [
                'content' => $content,
                'revision' => $revision,
                'diff' => $this->revisions->diff($revisionId, $siteId),
            ]
        );
    }
    public function restore(Request $request): Response
    {
        $actor = $this->requireCapability($request, 'content.update');

        $siteId = $this->siteId($request);
        $contentId = $this->intParam($request, 'id');
        $revisionId = $this->intParam($request, 'revision_id');

        try {
            $this->contents->get($contentId, $siteId);
        } catch (ContentNotFoundException) {
            throw HttpException::notFound();
        }

        $revision = $this->revisions->get($revisionId, $siteId);

        if ((int) $revision->contentId !== $contentId) {
            // A revision belongs to exactly one article; anything else is a mismatched form.
            throw HttpException::notFound();
        }

        // Snapshot what is live now, so the restore can itself be undone.
        $this->revisions->snapshot($contentId, $siteId, (int) $actor->id());

        $restored = $this->revisions->restore($revisionId, $siteId, (int) $actor->id());

        $this->flash('success', 'Restored revision ' . $revisionId . ' as revision ' . $restored . '.');

        return $this->redirect('/admin/content/' . $contentId . '/revisions');
    }
}
