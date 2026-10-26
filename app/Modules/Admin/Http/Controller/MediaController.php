<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Controller;

use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Http\AdminController;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Media\Entity\UploadedFile;
use CMS\Modules\Media\Exceptions\InvalidUploadException;
use CMS\Modules\Media\Exceptions\MediaInUseException;
use CMS\Modules\Media\Exceptions\MediaNotFoundException;
use CMS\Modules\Media\Exceptions\MediaTooLargeException;
use CMS\Modules\Media\Exceptions\UnsupportedMediaTypeException;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * The media library, and the picker it doubles as.
 *
 * There is no JavaScript here. Choosing an image for a field is an ordinary form post that
 * names the target, and the redirect returns to wherever the picker was opened from. That
 * keeps the flow testable through HTTP and working on a browser with scripting off.
 *
 * Validation belongs to the media service (MIME sniffing, size, extension); this controller
 * only checks that a file arrived and turns a failure into a message.
 */
final class MediaController extends AdminController
{
    private const PER_PAGE = 24;

    public function __construct(
        AdminView $view,
        SessionInterface $session,
        CsrfTokenManagerInterface $csrf,
        ActorProviderInterface $actors,
        private readonly MediaServiceInterface $media,
    ) {
        parent::__construct($view, $session, $csrf, $actors);
    }

    public function index(Request $request): Response
    {
        $actor = $this->requireCapability($request, 'media.read');
        $siteId = $this->siteId($request);

        $page = max(1, $this->intParam($request, 'page', 1));
        $items = $this->media->listForSite($siteId, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return $this->html($request, 'media/index', 'Media', [
            'items' => $items,
            'page' => $page,
            'pick' => $this->stringParam($request, 'pick'),
            'contentId' => $this->intParam($request, 'content_id'),
            'blockIndex' => $this->intParam($request, 'index'),
            'canUpload' => $actor->can('media.upload'),
            'canDelete' => $actor->can('media.delete'),
            'uploadError' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $actor = $this->requireCapability($request, 'media.upload');
        $siteId = $this->siteId($request);

        $file = $request->file('file');

        if ($file === null) {
            return $this->libraryWithError($request, 'Choose a file to upload.');
        }

        $altText = $this->stringParam($request, 'alt_text');

        try {
            $this->media->upload(
                $siteId,
                (int) $actor->id(),
                UploadedFile::fromFilesArray($file),
                $altText !== '' ? $altText : null,
            );
        } catch (MediaTooLargeException $exception) {
            return $this->libraryWithError($request, $exception->getMessage(), 422);
        } catch (UnsupportedMediaTypeException $exception) {
            return $this->libraryWithError($request, $exception->getMessage(), 422);
        } catch (InvalidUploadException $exception) {
            return $this->libraryWithError($request, $exception->getMessage(), 422);
        }

        $this->flash('success', 'Uploaded.');

        return $this->redirect($this->libraryUrl($request));
    }

    public function destroy(Request $request): Response
    {
        $this->requireCapability($request, 'media.delete');

        $id = $this->intParam($request, 'id');

        try {
            $this->media->delete($id, $this->siteId($request));
        } catch (MediaNotFoundException) {
            throw \CMS\Core\Http\HttpException::notFound();
        } catch (MediaInUseException $exception) {
            // Deleting an image a published article still points at would leave a broken
            // image on the live site, so the service refuses and says how many references.
            $this->flash('error', $exception->getMessage());

            return $this->redirect($this->libraryUrl($request));
        }

        $this->flash('success', 'Deleted.');

        return $this->redirect($this->libraryUrl($request));
    }

    /**
     * Re-render the library with a message, rather than redirecting: the upload form is on
     * this page, so the person is already where they need to be.
     */
    private function libraryWithError(Request $request, string $message, int $status = 422): Response
    {
        $siteId = $this->siteId($request);

        return $this->html($request, 'media/index', 'Media', [
            'items' => $this->media->listForSite($siteId, self::PER_PAGE, 0),
            'page' => 1,
            'pick' => $this->stringParam($request, 'pick'),
            'contentId' => $this->intParam($request, 'content_id'),
            'blockIndex' => $this->intParam($request, 'index'),
            'canUpload' => $this->actor($request)->can('media.upload'),
            'canDelete' => $this->actor($request)->can('media.delete'),
            'uploadError' => $message,
        ], $status);
    }

    /**
     * Back to the library, keeping picker context so a chosen image can still be applied.
     */
    private function libraryUrl(Request $request): string
    {
        $pick = $this->stringParam($request, 'pick');

        if ($pick === '') {
            return '/admin/media';
        }

        $query = array_filter([
            'pick' => $pick,
            'content_id' => $this->intParam($request, 'content_id'),
            'index' => $pick === 'block' ? $this->intParam($request, 'index') : 0,
        ]);

        return '/admin/media?' . http_build_query($query);
    }
}
