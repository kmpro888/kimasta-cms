<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use CMS\Modules\Identity\Contracts\RoleServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Shared\Contracts\SessionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * Managing the people who can sign in.
 *
 * Adding an editor used to mean a CLI snippet or direct SQL. The services existed; the screen did
 * not.
 *
 * The lockout guards are the point of the last few tests: a CMS whose only administrator can
 * remove their own role, disable themselves, or be deleted is a CMS that needs a database client
 * to recover.
 */
#[CoversClass(\CMS\Modules\Admin\Http\Controller\UserController::class)]
final class AdminUserTest extends AdminTestCase
{
    private function roleId(string $slug): int
    {
        foreach ($this->app->container()->get(RoleServiceInterface::class)->listForSite($this->siteId) as $role) {
            if ($role->slug === $slug) {
                return (int) $role->id;
            }
        }

        self::fail('No role ' . $slug);
    }

    private function users(): UserServiceInterface
    {
        return $this->app->container()->get(UserServiceInterface::class);
    }

    /**
     * The service has no findByUsername(); the list is small and this keeps the search honest.
     */
    private function byUsername(string $username): ?\CMS\Modules\Identity\Entity\User
    {
        foreach ($this->users()->listForSite($this->siteId) as $user) {
            if ($user->username === $username) {
                return $user;
            }
        }

        return null;
    }

    private function roles(): RoleServiceInterface
    {
        return $this->app->container()->get(RoleServiceInterface::class);
    }

    #[Test]
    public function theListRequiresTheReadCapability(): void
    {
        $this->loginAs($this->makeUser('writer', 'contributor'));

        // A contributor has no reason to see who else works here.
        self::assertSame(403, $this->get('/admin/users')->status());
    }

    #[Test]
    public function anEditorCanSeeTheListButNotAddAnyone(): void
    {
        $this->loginAs($this->makeUser('boss', 'editor'));

        $response = $this->get('/admin/users');
        $html = $this->body($response);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('boss', $html);
        self::assertStringNotContainsString('Add a person', $html, 'An editor may read, not create.');

        self::assertSame(403, $this->get('/admin/users/new')->status());
    }

    #[Test]
    public function anAdministratorCanAddSomebodyWhoCanSignIn(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $response = $this->post('/admin/users', [
            'username' => 'neweditor',
            'email' => 'neweditor@example.com',
            'display_name' => 'New Editor',
            'password' => 'a-real-password',
            'status' => 'active',
            'role_ids' => [$this->roleId('editor')],
        ]);

        self::assertSame(303, $response->status());
        self::assertSame('/admin/users', $this->location($response));

        $created = $this->byUsername('neweditor', $this->siteId);
        self::assertNotNull($created);
        self::assertSame('New Editor', $created->displayName);

        $roles = array_map(static fn ($role): string => $role->slug, $this->roles()->rolesForUser((int) $created->id, $this->siteId));
        self::assertSame(['editor'], $roles);

        // The password really works, through the real sign-in form.
        $this->app->container()->get(SessionInterface::class)->destroy();

        $login = $this->post('/admin/login', [
            'identifier' => 'neweditor',
            'password' => 'a-real-password',
        ]);

        self::assertSame(303, $login->status());
        self::assertSame('/admin', $this->location($login));
    }

    #[Test]
    public function aDuplicateUsernameIsRefusedWithAFormError(): void
    {
        $this->makeUser('taken', 'contributor');
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $response = $this->post('/admin/users', [
            'username' => 'taken',
            'email' => 'other@example.com',
            'password' => 'a-real-password',
        ]);

        self::assertSame(409, $response->status());
        self::assertStringContainsString('already taken', $this->body($response));
    }

    #[Test]
    public function aShortPasswordIsRefusedByThePolicy(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $response = $this->post('/admin/users', [
            'username' => 'shorty',
            'email' => 'shorty@example.com',
            'password' => 'short',
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('at least 8 characters', $this->body($response));
        self::assertNull($this->byUsername('shorty', $this->siteId));
    }

    #[Test]
    public function anAccountCanBeRenamedAndDisabled(): void
    {
        $target = $this->makeUser('writer', 'contributor');
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $response = $this->post('/admin/users/' . $target, [
            'email' => 'writer@example.com',
            'display_name' => 'Renamed Writer',
            'status' => 'disabled',
            'role_ids' => [$this->roleId('contributor')],
        ]);

        self::assertSame(303, $response->status());

        $updated = $this->users()->find($target, $this->siteId);
        self::assertNotNull($updated);
        self::assertSame('Renamed Writer', $updated->displayName);
        self::assertSame('disabled', $updated->status);
    }

    #[Test]
    public function rolesCanBeReplaced(): void
    {
        $target = $this->makeUser('writer', 'contributor');
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $this->post('/admin/users/' . $target, [
            'email' => 'writer@example.com',
            'display_name' => 'Writer',
            'status' => 'active',
            'role_ids' => [$this->roleId('reviewer')],
        ]);

        $roles = array_map(
            static fn ($role): string => $role->slug,
            $this->roles()->rolesForUser($target, $this->siteId)
        );

        self::assertSame(['reviewer'], $roles, 'The old role must be revoked, not merely added to.');
    }

    #[Test]
    public function youCannotDisableYourself(): void
    {
        $admin = $this->makeUser('boss', 'administrator');
        $this->loginAs($admin);

        $response = $this->post('/admin/users/' . $admin, [
            'email' => 'boss@example.com',
            'display_name' => 'Boss',
            'status' => 'disabled',
            'role_ids' => [$this->roleId('administrator')],
        ]);

        self::assertSame(409, $response->status());
        self::assertSame('active', $this->users()->find($admin, $this->siteId)?->status);
    }

    #[Test]
    public function youCannotRemoveYourOwnAdministratorRole(): void
    {
        $admin = $this->makeUser('boss', 'administrator');
        $this->loginAs($admin);

        $response = $this->post('/admin/users/' . $admin, [
            'email' => 'boss@example.com',
            'display_name' => 'Boss',
            'status' => 'active',
            'role_ids' => [$this->roleId('editor')],
        ]);

        self::assertSame(409, $response->status());
        self::assertStringContainsString('own administrator role', $this->body($response));
    }

    #[Test]
    public function youCannotDeleteYourself(): void
    {
        $admin = $this->makeUser('boss', 'administrator');
        $this->loginAs($admin);

        $response = $this->post('/admin/users/' . $admin . '/delete');

        self::assertSame(303, $response->status());
        self::assertNotNull($this->users()->find($admin, $this->siteId));
    }

    #[Test]
    public function theLastAdministratorCannotBeDeleted(): void
    {
        $admin = $this->makeUser('boss', 'administrator');
        $this->loginAs($admin);

        // A second administrator, who then deletes the first.
        $second = $this->makeUser('second', 'administrator');
        $this->roles()->revokeRole($second, $this->roleId('administrator'), $this->siteId);

        $response = $this->post('/admin/users/' . $second . '/delete');

        // `second` is not an administrator any more, so deleting them is fine; the guard is about
        // the account being the last one holding the role.
        self::assertSame(303, $response->status());
        self::assertNull($this->users()->find($second, $this->siteId));

        // Now `boss` is the last administrator: deleting them must be refused even by themselves.
        $selfDelete = $this->post('/admin/users/' . $admin . '/delete');
        self::assertSame(303, $selfDelete->status());
        self::assertNotNull($this->users()->find($admin, $this->siteId));
    }

    #[Test]
    public function aUserFromAnotherTenantIsA404(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        $foreign = (int) $this->users()->create($otherSite, [
            'username' => 'theirs',
            'email' => 'theirs@example.com',
            'password' => 'correct-horse-battery',
            'display_name' => 'Theirs',
        ])->id;

        $this->loginAs($this->makeUser('boss', 'administrator'));

        self::assertSame(404, $this->get('/admin/users/' . $foreign)->status());
        self::assertSame(404, $this->post('/admin/users/' . $foreign . '/delete')->status());
        self::assertNotNull($this->users()->find($foreign, $otherSite));
    }

    #[Test]
    public function thePeopleEntryIsOnlyShownToSomeoneWhoCanUseIt(): void
    {
        $this->loginAs($this->makeUser('writer', 'contributor'));
        self::assertStringNotContainsString('href="/admin/users"', $this->body($this->get('/admin')));

        $this->loginAs($this->makeUser('boss', 'administrator'));
        self::assertStringContainsString('href="/admin/users"', $this->body($this->get('/admin')));
    }
}
