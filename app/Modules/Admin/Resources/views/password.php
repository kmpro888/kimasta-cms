<?php

declare(strict_types=1);

/**
 * Change your own password.
 *
 * The current password is asked for even though the session is already authenticated: a
 * session left open on a shared machine must not be enough to lock the owner out.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$error = $view->get('error');
$minimum = (int) $view->get('minimum', 8);
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title">Change password</h1>
        <p class="page-head__sub">At least <?= $e($minimum) ?> characters.</p>
    </div>

    <p class="page-head__back"><a href="/admin">&larr; Back to the dashboard</a></p>
</div>

<?php if (is_string($error) && $error !== ''): ?>
    <p class="notice notice--error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>

<form class="panel" method="post" action="/admin/password" autocomplete="off">
    <input type="hidden" name="_token" value="<?= $e($view->get('csrf')) ?>">

    <label class="field">
        <span class="field__label">Current password</span>
        <input class="field__input" type="password" name="current_password"
               autocomplete="current-password" required autofocus>
    </label>

    <label class="field">
        <span class="field__label">New password</span>
        <input class="field__input" type="password" name="new_password"
               autocomplete="new-password" minlength="<?= $e($minimum) ?>" required>
    </label>

    <label class="field">
        <span class="field__label">Repeat the new password</span>
        <input class="field__input" type="password" name="confirm_password"
               autocomplete="new-password" minlength="<?= $e($minimum) ?>" required>
    </label>

    <div class="actions">
        <button class="button button--primary" type="submit">Change password</button>
    </div>
</form>

<p class="hint">
    Changing your password signs out other browsers only when they next have to prove who they are.
    Treat a shared computer as shared: sign out when you are done.
</p>
