<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Api\Http\ApiController;
use CMS\Modules\Api\Resources\TermResource;
use CMS\Modules\Api\Validation\Validator;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use CMS\Modules\Taxonomy\Exceptions\TermNotFoundException;

/**
 * Taxonomy endpoints.
 *
 * Reading is public; creating and deleting terms requires `taxonomy.manage`.
 */
final class TaxonomyController extends ApiController
{
    public function __construct(
        private readonly TaxonomyServiceInterface $taxonomy,
        private readonly TermResource $termResource,
    ) {
    }

    /**
     * GET /taxonomies/{taxonomy}/terms
     */
    public function index(Request $request): Response
    {
        $siteId = $this->siteId($request);
        $taxonomy = $this->taxonomyParam($request);

        $terms = $this->taxonomy->listTerms($siteId, $taxonomy);

        return $this->ok(array_map(fn ($term): array => $this->termResource->toArray($term), $terms));
    }

    /**
     * POST /taxonomies/{taxonomy}/terms
     */
    public function store(Request $request): Response
    {
        $this->requireCapability($request, 'taxonomy.manage');
        $siteId = $this->siteId($request);
        $taxonomyName = $this->taxonomyParam($request);

        $data = $request->jsonBody();
        $validator = Validator::make($data)
            ->required('name')
            ->string('name', 190)
            ->string('description', 1000)
            ->string('slug', 190)
            ->integer('parent_id', 1);

        $this->ensureValid($validator);

        $term = $this->taxonomy->createTerm(
            $siteId,
            $taxonomyName,
            (string) $data['name'],
            isset($data['description']) && is_string($data['description']) ? $data['description'] : null,
            isset($data['parent_id']) ? (int) $data['parent_id'] : null,
            isset($data['slug']) && is_string($data['slug']) ? $data['slug'] : null,
        );

        return $this->ok($this->termResource->toArray($term), [], 201);
    }

    /**
     * DELETE /taxonomies/terms/{id}
     */
    public function destroy(Request $request): Response
    {
        $this->requireCapability($request, 'taxonomy.manage');
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        try {
            $this->taxonomy->deleteTerm($id, $siteId);
        } catch (TermNotFoundException) {
            throw HttpException::notFound();
        }

        return new Response('', 204);
    }

    /**
     * The taxonomy name from the path.
     */
    private function taxonomyParam(Request $request): string
    {
        $name = $request->attribute('route.taxonomy');
        $name = is_string($name) ? trim($name) : '';

        if ($name === '' || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw HttpException::notFound();
        }

        return $name;
    }
}
