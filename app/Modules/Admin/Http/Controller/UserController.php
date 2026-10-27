<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Http\AdminController;
use CMS\Modules\Audit\Contracts\AuditLoggerInterface;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Identity\Contracts\RoleServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Modules\Identity\Entity\User;
use CMS\Modules\Identity\Exceptions\DuplicateUserException;
use CMS\Modules\Identity\Support\PasswordPolicy;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * The people who can sign in to this site, and what they may do.
 *
 * Until now adding an editor meant a CLI snippet or direct SQL, which is the wrong thing to ask of
 * whoever runs the site. The services already existed; only the screen did not.
 *
 * Two lockout guards matter more than the rest of this file:
 *
 *  - you cannot delete yourself, and
 *  - you cannot remove the last administrator, or remove your own administrator role.
 *
 * A CMS whose only administrator can lock themselves out is a CMS that needs a database client to
 * recover, which is exactly what this screen exists to avoid.
 */
final class UserController extends AdminController
{
    public function __construct(
        AdminView $view,
        SessionInterface $session,
        CsrfTokenManagerInterface $csrf,
        ActorProviderInterface $actors,
        private readonly UserServiceInterface $users,
        private readonly RoleServiceInterface $roles,
        private readonly AuditLoggerInterface $audit,
    ) {
        parent::__construct($view, $session, $csrf, $actors);
    }

    public function index(Request $request): Response
    {
        $this->requireCapability($request, 'users.read');
        $siteId = $this->siteId($request);
        $actor = $this->actor($request);

        $everyone = $this->users->listForSite($siteId);
        $roles = [];

        foreach ($everyone as $user) {
            $roles[(int) $user->id] = $this->roles->rolesForUser((int) $user->id, $siteId);
        }

        return $this->html($request, 'users/index', 'People', [
            'users' => $everyone,
            'rolesByUser' => $roles,
            'roleList' => $this->roles->listForSite($siteId),
            'canCreate' => $actor->can('users.create'),
            'canEdit' => $actor->can('users.update'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->requireCapability($request, 'users.create');

        return $this->form($request, null, [
            'username' => '',
            'email' => '',
            'display_name' => '',
            'status' => User::STATUS_ACTIVE,
            'role_ids' => [],
        ], []);
    }

    public function store(Request $request): Response
    {
        $this->requireCapability($request, 'users.create');
        $siteId = $this->siteId($request);

        $values = $this->values($request);

        try {
            $user = $this->users->create($siteId, [
                'username' => $values['username'],
                'email' => $values['email'],
                'password' => $this->stringParam($request, 'password'),
                'display_name' => $values['display_name'] !== '' ? $values['display_name'] : $values['username'],
                'status' => $values['status'],
            ]);
        } catch (DuplicateUserException $exception) {
            return $this->form($request, null, $values, [
                'username' => ['That username or email is already taken on this site.'],
            ], 409);
        } catch (\InvalidArgumentException $exception) {
            return $this->form($request, null, $values, [
                'password' => [$exception->getMessage()],
            ], 422);
        }

        $this->applyRoles($request, (int) $user->id, $values['role_ids']);

        $this->audit->record($this->siteId($request), $this->actor($request), 'user.created', 'user', (int) $user->id, ['username' => $values['username']]);
        $this->flash('success', 'Added ' . $values['username'] . '.');

        return $this->redirect('/admin/users');
    }

    public function edit(Request $request): Response
    {
        $this->requireCapability($request, 'users.read');

        $user = $this->findUser($request);

        return $this->form($request, $user, [
            'username' => $user->username,
            'email' => $user->email,
            'display_name' => $user->displayName,
            'status' => $user->status,
            'role_ids' => array_map(
                static fn ($role): int => (int) $role->id,
                $this->roles->rolesForUser((int) $user->id, $this->siteId($request))
            ),
        ], []);
    }

    public function save(Request $request): Response
    {
        $actor = $this->requireCapability($request, 'users.update');
        $siteId = $this->siteId($request);

        $user = $this->findUser($request);
        $userId = (int) $user->id;
        $values = $this->values($request);

        $isSelf = $userId === (int) $actor->id();

        // Disabling your own account would lock you out of the screen you are using.
        if ($isSelf && $values['status'] !== User::STATUS_ACTIVE) {
            return $this->form($request, $user, $values, [
                'status' => ['You cannot disable your own account.'],
            ], 409);
        }

        if ($values['display_name'] !== '' || $values['email'] !== '') {
            try {
                $this->users->update($userId, $siteId, array_filter([
                    'email' => $values['email'],
                    'display_name' => $values['display_name'],
                ], static fn (string $value): bool => $value !== ''));
            } catch (DuplicateUserException) {
                return $this->form($request, $user, $values, [
                    'email' => ['Another account already uses that email.'],
                ], 409);
            } catch (\InvalidArgumentException $exception) {
                return $this->form($request, $user, $values, [
                    'email' => [$exception->getMessage()],
                ], 422);
            }
        }

        if ($values['status'] !== $user->status) {
            $this->users->update($userId, $siteId, ['status' => $values['status']]);
        }

        $rolesError = $this->applyRoles($request, $userId, $values['role_ids'], $isSelf);

        if ($rolesError !== null) {
            return $this->form($request, $user, $values, ['role_ids' => [$rolesError]], 409);
        }

        $this->audit->record($this->siteId($request), $this->actor($request), 'user.updated', 'user', $userId, ['username' => $values['username'] ?? '']);
        $this->flash('success', 'Saved.');

        return $this->redirect('/admin/users/' . $userId);
    }

    public function delete(Request $request): Response
    {
        $actor = $this->requireCapability($request, 'users.delete');
        $user = $this->findUser($request);

        if ((int) $user->id === (int) $actor->id()) {
            $this->flash('error', 'You cannot delete your own account.');

            return $this->redirect('/admin/users/' . (int) $user->id);
        }

        if ($this->wouldLeaveNoAdministrator($this->siteId($request), $user)) {
            $this->flash('error', 'That is the last administrator. Promote somebody else first.');

            return $this->redirect('/admin/users/' . (int) $user->id);
        }

        $this->users->delete((int) $user->id, $this->siteId($request));

        // Recorded after the delete, against the id that no longer exists: the log keeps the
        // subject even though the row is gone.
        $this->audit->record($this->siteId($request), $this->actor($request), 'user.deleted', 'user', (int) $user->id, ['username' => $user->username]);
        $this->flash('success', 'Deleted ' . $user->username . '.');

        return $this->redirect('/admin/users');
    }

    /**
     * Apply the submitted role set, and return a message when a guard refused it.
     *
     * @param list<int> $roleIds
     */
    private function applyRoles(Request $request, int $userId, array $roleIds, bool $isSelf = false): ?string
    {
        if (!$this->actor($request)->can('roles.assign')) {
            // Without the capability the role set is left exactly as it was.
            return null;
        }

        $siteId = $this->siteId($request);
        $all = $this->roles->listForSite($siteId);

        $administratorId = null;

        foreach ($all as $role) {
            if ($role->slug === 'administrator') {
                $administratorId = (int) $role->id;
            }
        }

        // Removing your own administrator role is the quiet version of locking yourself out.
        if ($isSelf && $administratorId !== null && !in_array($administratorId, $roleIds, true)) {
            return 'You cannot remove your own administrator role.';
        }

        $current = array_map(
            static fn ($role): int => (int) $role->id,
            $this->roles->rolesForUser($userId, $siteId)
        );

        foreach ($roleIds as $roleId) {
            if (!in_array($roleId, $current, true)) {
                $this->roles->assignRole($userId, $roleId, $siteId);
            }
        }

        foreach ($current as $roleId) {
            if (!in_array($roleId, $roleIds, true)) {
                $this->roles->revokeRole($userId, $roleId, $siteId);
            }
        }

        return null;
    }

    /**
     * Would deleting or demoting this user leave the site without an administrator?
     */
    private function wouldLeaveNoAdministrator(int $siteId, User $user): bool
    {
        foreach ($this->roles->listForSite($siteId) as $role) {
            if ($role->slug !== 'administrator') {
                continue;
            }

            $holderIds = $this->roles->usersWithCapability('settings.update', $siteId);
            $others = array_values(array_filter(
                $holderIds,
                static fn (int $id): bool => $id !== (int) $user->id
            ));

            return $others === [];
        }

        return false;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     */
    private function form(
        Request $request,
        ?User $user,
        array $values,
        array $errors,
        int $status = 200,
    ): Response {
        return $this->html($request, 'users/form', $user === null ? 'Add a person' : $user->username, [
            'user' => $user,
            'values' => $values,
            'errors' => $errors,
            'roleList' => $this->roles->listForSite($this->siteId($request)),
            'canAssignRoles' => $this->actor($request)->can('roles.assign'),
            'canDelete' => $this->actor($request)->can('users.delete'),
            'minimum' => PasswordPolicy::MIN_LENGTH,
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Request $request): array
    {
        $roleIds = $request->input('role_ids', []);
        $roleIds = is_array($roleIds)
            ? array_values(array_unique(array_filter(array_map('intval', $roleIds), static fn (int $id): bool => $id > 0)))
            : [];

        $status = $this->stringParam($request, 'status', User::STATUS_ACTIVE);
        $status = in_array($status, [User::STATUS_ACTIVE, User::STATUS_DISABLED], true)
            ? $status
            : User::STATUS_ACTIVE;

        return [
            'username' => trim($this->stringParam($request, 'username')),
            'email' => trim($this->stringParam($request, 'email')),
            'display_name' => trim($this->stringParam($request, 'display_name')),
            'status' => $status,
            'role_ids' => $roleIds,
        ];
    }

    private function findUser(Request $request): User
    {
        $user = $this->users->find($this->intParam($request, 'id'), $this->siteId($request));

        if ($user === null) {
            throw HttpException::notFound();
        }

        return $user;
    }
}
