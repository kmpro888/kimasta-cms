<?php

declare(strict_types=1);

/**
 * Sign-in screen. Rendered without the admin shell: there is no navigation to show yet.
 *
 * The error is a single message for every credential failure, so the form cannot be used to
 * find out which accounts exist.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);
$siteName = $view->get('site') !== null ? $view->get('site')->name() : 'Admin';
$error = $view->get('error');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($view->get('title')) ?> - <?= $e($siteName) ?></title>
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body class="is-login">
<main class="login">
    <h1 class="login__brand"><?= $e($siteName) ?></h1>

    <?php if (is_string($error) && $error !== ''): ?>
        <p class="notice notice--error" role="alert"><?= $e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/login" class="login__form">
        <input type="hidden" name="_token" value="<?= $e($view->get('csrf')) ?>">
        <input type="hidden" name="next" value="<?= $e($view->get('next')) ?>">

        <label class="field">
            <span class="field__label">Username or email</span>
            <input class="field__input" type="text" name="identifier" autocomplete="username"
                   required autofocus value="<?= $e($view->get('identifier')) ?>">
        </label>

        <label class="field">
            <span class="field__label">Password</span>
            <input class="field__input" type="password" name="password" autocomplete="current-password" required>
        </label>

        <button class="button button--primary button--block" type="submit">Sign in</button>
    </form>
</main>
</body>
</html>
