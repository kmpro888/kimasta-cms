<?php

declare(strict_types=1);

/**
 * One account: create it, or change it.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$user = $view->get('user');
$values = $view->get('values', []);
$errors = $view->get('errors', []);
$roleList = $view->get('roleList', []);
$canAssignRoles = (bool) $view->get('canAssignRoles', false);
$canDelete = (bool) $view->get('canDelete', false);
$minimum = (int) $view->get('minimum', 8);
$csrf = $view->get('csrf');

$selectedRoles = array_map('intval', (array) ($values['role_ids'] ?? []));
$isNew = $user === null;
$action = $isNew ? '/admin/users' : '/admin/users/' . $user->id;

$error = static function (string $field) use ($errors): string {
    $list = $errors[$field] ?? [];
    $first = is_array($list) && $list !== [] ? (string) reset($list) : '';

    return $first;
};
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title"><?= $isNew ? 'Add a person' : $e($user->username) ?></h1>
        <?php if (!$isNew): ?>
            <p class="page-head__sub">Joined <?= $e($user->createdAt->format('j M Y')) ?></p>
        <?php endif; ?>
    </div>

    <p class="page-head__back"><a href="/admin/users">&larr; All people</a></p>
</div>

<div class="editor">
    <form class="editor__main" method="post" action="<?= $e($action) ?>">
        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">

        <label class="field">
            <span class="field__label">Username</span>
            <input class="field__input" type="text" name="username" required
                   value="<?= $e($values['username'] ?? '') ?>"<?= $isNew ? '' : ' readonly' ?>>
            <?php if ($error('username') !== ''): ?>
                <span class="field__error"><?= $e($error('username')) ?></span>
            <?php endif; ?>
        </label>

        <label class="field">
            <span class="field__label">Email</span>
            <input class="field__input" type="email" name="email" required
                   value="<?= $e($values['email'] ?? '') ?>">
            <?php if ($error('email') !== ''): ?>
                <span class="field__error"><?= $e($error('email')) ?></span>
            <?php endif; ?>
        </label>

        <label class="field">
            <span class="field__label">Display name</span>
            <input class="field__input" type="text" name="display_name"
                   value="<?= $e($values['display_name'] ?? '') ?>"
                   placeholder="Used as the byline">
        </label>

        <?php if ($isNew): ?>
            <label class="field">
                <span class="field__label">Password</span>
                <input class="field__input" type="password" name="password"
                       autocomplete="new-password" minlength="<?= $e($minimum) ?>" required>
                <span class="hint">At least <?= $e($minimum) ?> characters. They can change it after signing in.</span>
                <?php if ($error('password') !== ''): ?>
                    <span class="field__error"><?= $e($error('password')) ?></span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <label class="field">
            <span class="field__label">Status</span>
            <select class="field__input" name="status">
                <option value="active"<?= ($values['status'] ?? 'active') === 'active' ? ' selected' : '' ?>>Active</option>
                <option value="disabled"<?= ($values['status'] ?? '') === 'disabled' ? ' selected' : '' ?>>Disabled</option>
            </select>
            <?php if ($error('status') !== ''): ?>
                <span class="field__error"><?= $e($error('status')) ?></span>
            <?php endif; ?>
        </label>

        <?php if ($canAssignRoles): ?>
            <fieldset class="panel">
                <legend class="field__label">Roles</legend>
                <?php foreach ($roleList as $role): ?>
                    <label class="check">
                        <input type="checkbox" name="role_ids[]" value="<?= $e($role->id) ?>"
                            <?= in_array((int) $role->id, $selectedRoles, true) ? 'checked' : '' ?>>
                        <span><?= $e($role->name) ?></span>
                    </label>
                <?php endforeach; ?>
                <?php if ($error('role_ids') !== ''): ?>
                    <span class="field__error"><?= $e($error('role_ids')) ?></span>
                <?php endif; ?>
            </fieldset>
        <?php endif; ?>

        <div class="actions">
            <button class="button button--primary" type="submit"><?= $isNew ? 'Add person' : 'Save' ?></button>
        </div>
    </form>

    <?php if (!$isNew && $canDelete): ?>
        <aside class="editor__side">
            <?php if ($canDelete): ?>
            <form class="panel" method="post" action="/admin/users/<?= $e($user->id) ?>/reset-password">
                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                <h2 class="panel__title">Password</h2>
                <p class="hint">
                    Issue a one-time reset link. It is shown once, right here, for you to pass on —
                    the public site has no "forgot password" page of its own.
                </p>
                <button class="button button--small button--block" type="submit">Issue reset link</button>
            </form>
            <?php endif; ?>

            <form class="panel" method="post" action="/admin/users/<?= $e($user->id) ?>/delete"
                  data-confirm="Delete this account?">
                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                <h2 class="panel__title">Danger</h2>
                <p class="hint">Deleting an account is permanent. The last administrator cannot be deleted.</p>
                <button class="button button--small button--danger button--block" type="submit">Delete account</button>
            </form>
        </aside>
    <?php endif; ?>
</div>
