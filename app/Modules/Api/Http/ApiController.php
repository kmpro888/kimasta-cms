<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\JsonResponse;
use CMS\Core\Http\Request;
use CMS\Modules\Api\Validation\Validator;
use CMS\Shared\Contracts\ActorInterface;
use CMS\Shared\Contracts\SiteContextInterface;

/**
 * Shared plumbing for every API controller.
 *
 * Controllers do **no** business logic: they validate shape, ask the actor for
 * permission, call one application service, and project the result through a
 * resource. Anything more belongs in a service.
 */
abstract class ApiController
{
    /**
     * The tenant, resolved from the request host by middleware.
     */
    protected function site(Request $request): SiteContextInterface
    {
        $site = $request->attribute('api.site');

        if (!$site instanceof SiteContextInterface) {
            // Landing here means the route ran without the auth middleware, which
            // is a wiring bug rather than a client error.
            throw HttpException::internalError('Tenant context is missing.');
        }

        return $site;
    }

    protected function siteId(Request $request): int
    {
        return $this->site($request)->id();
    }

    protected function actor(Request $request): ActorInterface
    {
        $actor = $request->attribute('api.actor');

        if (!$actor instanceof ActorInterface) {
            throw HttpException::internalError('Actor context is missing.');
        }

        return $actor;
    }

    /**
     * Require an authenticated actor.
     *
     * @throws HttpException 401
     */
    protected function requireAuthentication(Request $request): ActorInterface
    {
        $actor = $this->actor($request);

        if (!$actor->authenticated()) {
            throw HttpException::unauthenticated();
        }

        return $actor;
    }

    /**
     * Require a capability for a mutation.
     *
     * @throws HttpException 401 then 403
     */
    protected function requireCapability(Request $request, string $capability): ActorInterface
    {
        $actor = $this->requireAuthentication($request);

        if (!$actor->can($capability)) {
            throw HttpException::forbidden('Missing capability: ' . $capability . '.');
        }

        return $actor;
    }

    /**
     * @throws HttpException 422 with a field map
     */
    protected function ensureValid(Validator $validator): void
    {
        if (!$validator->fails()) {
            return;
        }

        throw new HttpException(
            422,
            'The submitted data is invalid.',
            'VALIDATION_ERROR',
            ['fields' => $validator->errors()]
        );
    }

    /**
     * @param mixed $data
     * @param array<string, mixed> $meta
     */
    protected function ok(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, $meta);
    }

    /**
     * Pagination metadata in the frozen `meta` object.
     *
     * @return array<string, int>
     */
    protected function paginationMeta(int $page, int $perPage, int $total, int $totalPages): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Read a positive integer route parameter (`{id}`), 404 when missing.
     */
    protected function intParam(Request $request, string $name): int
    {
        $id = (int) $request->attribute('route.' . $name, 0);

        if ($id <= 0) {
            throw HttpException::notFound();
        }

        return $id;
    }
}
