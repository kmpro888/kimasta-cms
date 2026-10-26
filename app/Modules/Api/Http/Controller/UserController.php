<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Api\Http\ApiController;
use CMS\Modules\Api\Resources\UserResource;
use CMS\Modules\Api\Validation\Validator;
use CMS\Modules\Identity\Contracts\RoleServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Modules\Identity\Exceptions\DuplicateUserException;

/**
 * User and role endpoints.
 *
 * Two rules protect accounts beyond simple capability checks:
 *
 *  - a user can never delete themselves, so an administrator cannot lock the site
 *    out of its last admin session by accident;
 *  - `password_hash` is never accepted or returned — passwords go through the
 *    dedicated service methods.
 */
final class UserController extends ApiController
{
    public function __construct(
        private readonly UserServiceInterface $users,
        private readonly RoleServiceInterface $roles,
        private readonly UserResource $resource,
    ) {
    }

    /**
     * GET /users
     */
    public function index(Request $request): Response
    {
        $this->requireCapability($request, 'users.read');
        $siteId = $this->siteId($request);

        $data = array_map(
            fn ($user): array => $this->resource->toArray($user),
            $this->users->listForSite($siteId)
        );

        return $this->ok($data);
    }

    /**
     * GET /users/{id}
     */
    public function show(Request $request): Response
    {
        $actor = $this->requireAuthentication($request);
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        // A user may always read their own record; reading others needs the grant.
        if ((int) $actor->id() !== $id && !$actor->can('users.read')) {
            throw HttpException::forbidden();
        }

        $user = $this->users->find($id, $siteId);

        if ($user === null) {
            throw HttpException::notFound();
        }

        return $this->ok($this->resource->toArray($user) + [
            'roles' => $this->roleSlugs($id, $siteId),
        ]);
    }

    /**
     * POST /users
     */
    public function store(Request $request): Response
    {
        $this->requireCapability($request, 'users.create');
        $siteId = $this->siteId($request);

        $data = $request->jsonBody();
        $validator = Validator::make($data)
            ->required('username', 'email', 'password')
            ->string('username', 60)
            ->string('email', 190)
            ->string('display_name', 190)
            ->string('password', 255);

        $this->ensureValid($validator);

        if (filter_var((string) $data['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['email' => ['This field must be a valid email address.']],
            ]);
        }

        try {
            $user = $this->users->create($siteId, [
                'username' => (string) $data['username'],
                'email' => (string) $data['email'],
                'password' => (string) $data['password'],
                'display_name' => isset($data['display_name']) && is_string($data['display_name'])
                    ? $data['display_name']
                    : (string) $data['username'],
            ]);
        } catch (DuplicateUserException) {
            throw new HttpException(409, 'A user with that username or email already exists.', 'RESOURCE_CONFLICT');
        }

        return $this->ok($this->resource->toArray($user), [], 201);
    }

    /**
     * PATCH /users/{id}
     */
    public function update(Request $request): Response
    {
        $this->requireCapability($request, 'users.update');
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        $this->assertUserExists($id, $siteId);

        $data = $request->jsonBody();
        $validator = Validator::make($data)
            ->string('display_name', 190)
            ->string('email', 190)
            ->oneOf('status', ['active', 'suspended']);

        $this->ensureValid($validator);

        $attributes = [];

        foreach (['display_name', 'email', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if ($attributes === []) {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['body' => ['No updatable field was supplied.']],
            ]);
        }

        return $this->ok($this->resource->toArray($this->users->update($id, $siteId, $attributes)));
    }

    /**
     * DELETE /users/{id}
     */
    public function destroy(Request $request): Response
    {
        $actor = $this->requireCapability($request, 'users.delete');
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        if ((int) $actor->id() === $id) {
            // Deleting yourself would revoke your own access mid-request and can
            // leave a site with no administrator.
            throw new HttpException(409, 'You cannot delete your own account.', 'INVALID_STATE_TRANSITION');
        }

        $this->assertUserExists($id, $siteId);
        $this->users->delete($id, $siteId);

        return new Response('', 204);
    }

    /**
     * GET /users/{id}/roles
     */
    public function roles(Request $request): Response
    {
        $this->requireCapability($request, 'users.read');
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        $this->assertUserExists($id, $siteId);

        return $this->ok($this->roleSlugs($id, $siteId));
    }

    /**
     * PUT /users/{id}/roles - replace the assigned set.
     */
    public function syncRoles(Request $request): Response
    {
        $this->requireCapability($request, 'roles.assign');
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        $this->assertUserExists($id, $siteId);

        $data = $request->jsonBody();
        $validator = Validator::make($data)->required('roles');

        $this->ensureValid($validator);

        if (!is_array($data['roles'])) {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['roles' => ['This field must be an array of role slugs.']],
            ]);
        }

        $known = [];

        foreach ($this->roles->listForSite($siteId) as $role) {
            $known[$role->slug] = (int) $role->id;
        }

        $requested = array_values(array_unique(array_map('strval', $data['roles'])));
        $unknown = array_diff($requested, array_keys($known));

        if ($unknown !== []) {
            throw new HttpException(422, 'Unknown role.', 'VALIDATION_ERROR', [
                'fields' => ['roles' => ['Unknown role: ' . implode(', ', $unknown) . '.']],
            ]);
        }

        $current = [];

        foreach ($this->roles->rolesForUser($id, $siteId) as $role) {
            $current[$role->slug] = (int) $role->id;
        }

        foreach (array_diff(array_keys($current), $requested) as $slug) {
            $this->roles->revokeRole($id, $current[$slug], $siteId);
        }

        foreach (array_diff($requested, array_keys($current)) as $slug) {
            $this->roles->assignRole($id, $known[$slug], $siteId);
        }

        return $this->ok($this->roleSlugs($id, $siteId));
    }

    /**
     * @return list<string>
     */
    private function roleSlugs(int $userId, int $siteId): array
    {
        return array_values(array_map(
            static fn ($role): string => (string) $role->slug,
            $this->roles->rolesForUser($userId, $siteId)
        ));
    }

    private function assertUserExists(int $id, int $siteId): void
    {
        if ($this->users->find($id, $siteId) === null) {
            throw HttpException::notFound();
        }
    }
}
