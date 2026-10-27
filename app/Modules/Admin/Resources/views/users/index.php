<?php

declare(strict_types=1);

/**
 * The people who can sign in to this site.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$users = $view->get('users', []);
$rolesByUser = $view->get('rolesByUser', []);
$canCreate = (bool) $view->get('canCreate', false);
$formatDate = static fn (mixed $value): string => $value instanceof \DateTimeImmutable
    ? $value->format('j M Y')
    : '';
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title">People</h1>
        <p class="page-head__sub"><?= $e(count($users)) ?> account(s) on this site</p>
    </div>

    <?php if ($canCreate): ?>
        <a class="button button--primary" href="/admin/users/new">Add a person</a>
    <?php endif; ?>
</div>

<?php if ($users === []): ?>
    <p class="empty">No accounts yet.</p>
<?php else: ?>
    <table class="table">
        <thead>
        <tr>
            <th scope="col">Name</th>
            <th scope="col">Username</th>
            <th scope="col">Email</th>
            <th scope="col">Roles</th>
            <th scope="col">Status</th>
            <th scope="col">Last sign-in</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <?php $userRoles = $rolesByUser[(int) $user->id] ?? []; ?>
            <tr>
                <td>
                    <a href="/admin/users/<?= $e($user->id) ?>"><?= $e($user->displayName) ?></a>
                </td>
                <td class="muted"><?= $e($user->username) ?></td>
                <td class="muted"><?= $e($user->email) ?></td>
                <td>
                    <?php foreach ($userRoles as $role): ?>
                        <span class="badge"><?= $e($role->name) ?></span>
                    <?php endforeach; ?>
                    <?php if ($userRoles === []): ?>
                        <span class="muted">none</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge badge--<?= $user->isActive() ? 'published' : 'trashed' ?>">
                        <?= $e($user->status) ?>
                    </span>
                </td>
                <td class="muted">
                    <?= $user->lastLoginAt === null ? 'never' : $e($formatDate($user->lastLoginAt)) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<p class="hint">
    An account with no role can sign in and see nothing. The administrator role is the only one
    that may change site settings or manage the people on this screen.
</p>
