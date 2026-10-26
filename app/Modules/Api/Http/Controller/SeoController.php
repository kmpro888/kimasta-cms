<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Api\Http\ApiController;
use CMS\Modules\Api\Resources\SeoResource;
use CMS\Modules\Api\Validation\Validator;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Exceptions\ContentNotFoundException;
use CMS\Modules\Seo\Contracts\SeoServiceInterface;

/**
 * Per-content SEO endpoints.
 *
 * The GET returns the **resolved** metadata, including derived defaults, so an editor
 * sees exactly what the page will render rather than the raw overrides.
 */
final class SeoController extends ApiController
{
    public function __construct(
        private readonly SeoServiceInterface $seo,
        private readonly ContentServiceInterface $contents,
        private readonly SeoResource $resource,
    ) {
    }

    /**
     * GET /content/{id}/seo
     */
    public function show(Request $request): Response
    {
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        $this->assertContentVisible($request, $id, $siteId);

        return $this->ok($this->resource->toArray($this->seo->metaFor($id, $siteId)));
    }

    /**
     * PUT /content/{id}/seo
     */
    public function store(Request $request): Response
    {
        // Authenticate before touching the row: otherwise an anonymous caller could tell a
        // draft that exists (403/401) from one that does not (404), which leaks the presence
        // of unpublished work.
        $actor = $this->requireAuthentication($request);

        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        // An author may describe their own piece. Requiring `content.update` would let a
        // contributor write an article but never set its title tag or description, which
        // makes the contributor workflow impossible through the API.
        $content = $this->findContent($id, $siteId);

        if (!$actor->can('content.update') && (int) $content->authorId !== (int) $actor->id()) {
            throw HttpException::forbidden('You may only change SEO for your own content.');
        }

        $data = $request->jsonBody();
        $validator = Validator::make($data)
            ->string('meta_title', 255)
            ->string('meta_description', 320)
            ->string('canonical_url', 500)
            ->string('og_title', 255)
            ->string('og_description', 320)
            ->string('schema_type', 80)
            ->boolean('robots_index')
            ->boolean('robots_follow')
            ->integer('og_image_id', 1);

        $this->ensureValid($validator);

        $attributes = [];

        foreach (['meta_title', 'meta_description', 'canonical_url', 'og_title', 'og_description', 'schema_type'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = is_string($data[$field]) ? $data[$field] : null;
            }
        }

        foreach (['robots_index', 'robots_follow'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field] === true
                    || $data[$field] === 1
                    || $data[$field] === '1'
                    || $data[$field] === 'true';
            }
        }

        if (array_key_exists('og_image_id', $data)) {
            $attributes['og_image_id'] = $data['og_image_id'] === null ? null : (int) $data['og_image_id'];
        }

        // A canonical must be absolute; a relative one would render as-is and point
        // at whatever the current URL happens to be.
        if (isset($attributes['canonical_url']) && is_string($attributes['canonical_url'])) {
            $canonical = trim($attributes['canonical_url']);

            if ($canonical !== '' && filter_var($canonical, FILTER_VALIDATE_URL) === false) {
                throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                    'fields' => ['canonical_url' => ['This field must be an absolute URL.']],
                ]);
            }
        }

        try {
            $meta = $this->seo->store($id, $siteId, $attributes);
        } catch (ContentNotFoundException) {
            throw HttpException::notFound();
        }

        return $this->ok($this->resource->toArray($meta));
    }

    /**
     * Drafts stay invisible to a caller who cannot read private content.
     */
    private function assertContentVisible(Request $request, int $id, int $siteId): void
    {
        $content = $this->findContent($id, $siteId);

        if (!$content->isPubliclyVisible() && !$this->actor($request)->can('content.read')) {
            throw HttpException::notFound();
        }
    }

    /**
     * Fetch the row, mapping a missing or foreign row to 404.
     */
    private function findContent(int $id, int $siteId): \CMS\Modules\Content\Entity\Content
    {
        try {
            return $this->contents->get($id, $siteId);
        } catch (ContentNotFoundException) {
            throw HttpException::notFound();
        }
    }
}
