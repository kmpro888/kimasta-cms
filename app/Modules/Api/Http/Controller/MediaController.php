<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Api\Http\ApiController;
use CMS\Modules\Api\Resources\MediaResource;
use CMS\Modules\Api\Validation\Validator;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Media\Entity\UploadedFile;
use CMS\Modules\Media\Exceptions\MediaInUseException;
use CMS\Modules\Media\Exceptions\MediaNotFoundException;

/**
 * Media endpoints.
 *
 * Upload is not exposed: `Request` has no multipart parsing, so a file upload cannot
 * be read without new HTTP infrastructure. Listing, reading and metadata are.
 */
final class MediaController extends ApiController
{
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly MediaServiceInterface $media,
        private readonly MediaResource $resource,
    ) {
    }

    /**
     * GET /media
     */
    public function index(Request $request): Response
    {
        $this->requireCapability($request, 'media.read');
        $siteId = $this->siteId($request);

        $page = max(1, (int) ($request->queryParam('page') ?? 1));
        $perPage = (int) ($request->queryParam('per_page') ?? 20);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $items = $this->media->listForSite($siteId, $perPage, ($page - 1) * $perPage);

        return $this->ok(array_map(fn ($media): array => $this->resource->toArray($media), $items), [
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * POST /media
     *
     * Multipart upload. The file is validated by the media service (MIME sniffing, size,
     * extension), not here: the controller only checks that a file arrived and maps the raw
     * request entry onto the module's value object.
     */
    public function store(Request $request): Response
    {
        $this->requireCapability($request, 'media.upload');
        $siteId = $this->siteId($request);
        $actor = $this->actor($request);

        $file = $request->file('file');

        if ($file === null) {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['file' => ['A file is required in the "file" field.']],
            ]);
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            // Surfacing the upload error beats a generic 422: a client that sent a file
            // larger than the server accepts needs to know that, not "invalid data".
            throw new HttpException(422, 'The file could not be uploaded.', 'VALIDATION_ERROR', [
                'fields' => ['file' => ['Upload error code ' . $file['error'] . '.']],
            ]);
        }

        $altText = $request->input('alt_text');
        $altText = is_string($altText) && trim($altText) !== '' ? trim($altText) : null;

        try {
            $media = $this->media->upload(
                $siteId,
                (int) $actor->id(),
                new UploadedFile(
                    tmpPath: $file['tmp_name'],
                    originalName: $file['name'],
                    size: $file['size'],
                    clientMime: $file['type'] !== '' ? $file['type'] : null,
                    error: $file['error'],
                ),
                $altText,
            );
        } catch (\InvalidArgumentException $exception) {
            throw new HttpException(422, $exception->getMessage(), 'VALIDATION_ERROR', [
                'fields' => ['file' => [$exception->getMessage()]],
            ]);
        }

        return $this->ok($this->resource->toArray($media), [], 201);
    }

    /**
     * GET /media/{id}
     */
    public function show(Request $request): Response
    {
        $this->requireCapability($request, 'media.read');
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        return $this->ok($this->resource->toArray($this->findMedia($id, $siteId)));
    }

    /**
     * PATCH /media/{id} - alt text and caption only.
     */
    public function update(Request $request): Response
    {
        $this->requireCapability($request, 'media.upload');
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        $this->findMedia($id, $siteId);

        $data = $request->jsonBody();
        $validator = Validator::make($data)
            ->string('alt_text', 500)
            ->string('caption', 1000);

        $this->ensureValid($validator);

        $attributes = [];

        foreach (['alt_text', 'caption'] as $field) {
            if (array_key_exists($field, $data)) {
                // An explicit null clears the value.
                $attributes[$field] = is_string($data[$field]) ? $data[$field] : null;
            }
        }

        if ($attributes === []) {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['body' => ['No updatable field was supplied.']],
            ]);
        }

        return $this->ok($this->resource->toArray($this->media->update($id, $siteId, $attributes)));
    }

    /**
     * DELETE /media/{id}
     *
     * Refuses while the file is still referenced, unless `force` is set. Deleting an
     * image that an article uses would leave a broken reference on a live page.
     */
    public function destroy(Request $request): Response
    {
        $this->requireCapability($request, 'media.delete');
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        $this->findMedia($id, $siteId);

        $force = $request->queryParam('force');
        $force = $force === '1' || $force === 'true';

        try {
            $this->media->delete($id, $siteId, $force);
        } catch (MediaInUseException) {
            throw new HttpException(
                409,
                'This file is still used by content. Pass ?force=1 to delete it anyway.',
                'RESOURCE_IN_USE'
            );
        }

        return new Response('', 204);
    }

    private function findMedia(int $id, int $siteId): \CMS\Modules\Media\Entity\Media
    {
        try {
            return $this->media->get($id, $siteId);
        } catch (MediaNotFoundException) {
            throw HttpException::notFound();
        }
    }
}
