<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Api\Http\ApiController;
use CMS\Modules\Api\Resources\RedirectResource;
use CMS\Modules\Api\Validation\Validator;
use CMS\Modules\Seo\Contracts\RedirectServiceInterface;
use CMS\Modules\Seo\Exceptions\InvalidRedirectException;
use CMS\Modules\Seo\Exceptions\RedirectLoopException;

/**
 * Redirect endpoints.
 *
 * Creation is not a bare upsert: the service rejects loops and unsupported status
 * codes, and those refusals are surfaced as 409/422 rather than swallowed into a
 * generic failure.
 */
final class RedirectController extends ApiController
{
    public function __construct(
        private readonly RedirectServiceInterface $redirects,
        private readonly RedirectResource $resource,
    ) {
    }

    /**
     * GET /redirects
     */
    public function index(Request $request): Response
    {
        $this->requireCapability($request, 'settings.read');
        $siteId = $this->siteId($request);

        return $this->ok(array_map(
            fn (array $row): array => $this->resource->toArray($row),
            $this->redirects->listForSite($siteId)
        ));
    }

    /**
     * POST /redirects
     */
    public function store(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');
        $siteId = $this->siteId($request);

        $data = $request->jsonBody();
        $validator = Validator::make($data)
            ->required('source_path', 'target_url')
            ->string('source_path', 500)
            ->string('target_url', 1000)
            ->integer('status_code');

        $this->ensureValid($validator);

        $statusCode = isset($data['status_code']) ? (int) $data['status_code'] : 301;

        try {
            $this->redirects->store(
                $siteId,
                (string) $data['source_path'],
                (string) $data['target_url'],
                $statusCode,
            );
        } catch (RedirectLoopException) {
            // A distinct code so a client can explain the problem instead of showing
            // "validation failed".
            throw new HttpException(409, 'That redirect would create a loop.', 'REDIRECT_LOOP');
        } catch (InvalidRedirectException $exception) {
            throw new HttpException(422, $exception->getMessage(), 'VALIDATION_ERROR', [
                'fields' => ['redirect' => [$exception->getMessage()]],
            ]);
        }

        return $this->ok([
            'source_path' => (string) $data['source_path'],
            'target_url' => (string) $data['target_url'],
            'status_code' => $statusCode,
        ], [], 201);
    }

    /**
     * DELETE /redirects?source_path=/old
     *
     * The source path is a query parameter rather than a route segment because it
     * contains slashes.
     */
    public function destroy(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');
        $siteId = $this->siteId($request);

        $sourcePath = $request->queryParam('source_path');

        if (!is_string($sourcePath) || trim($sourcePath) === '') {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['source_path' => ['This field is required.']],
            ]);
        }

        if (!$this->redirects->delete($siteId, $sourcePath)) {
            throw HttpException::notFound();
        }

        return new Response('', 204);
    }
}
