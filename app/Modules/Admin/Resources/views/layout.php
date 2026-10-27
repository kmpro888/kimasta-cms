<?php

declare(strict_types=1);

/**
 * Admin shell.
 *
 * Receives `$view` and `$renderer` only, like every template in this project. The site name,
 * the account and the CSRF token all arrive already prepared, so the shell contains no logic
 * beyond choosing what to show.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$siteName = $view->get('site') !== null ? $view->get('site')->name() : 'Admin';
$actor = $view->get('actor');
$flash = $view->get('flash');
$path = (string) $view->get('path');

$canReview = $actor !== null && $actor->can('workflow.review');
$canModerate = $actor !== null && $actor->can('comment.moderate');

$navItems = [
    '/admin' => 'Dashboard',
    '/admin/content' => 'Content',
    '/admin/media' => 'Media',
    '/admin/notifications' => 'Notifications',
];

// Only shown to someone who can act on it: a menu entry that leads to a 403 page is worse than
// no menu entry.
if ($canModerate) {
    $navItems['/admin/comments'] = 'Comments';
}

if ($actor !== null && $actor->can('users.read')) {
    $navItems['/admin/users'] = 'People';
}

if ($actor !== null && $actor->can('settings.update')) {
    $navItems['/admin/redirects'] = 'Redirects';
    $navItems['/admin/ads'] = 'Ads';
    $navItems['/admin/plugins'] = 'Plugins';
    $navItems['/admin/settings'] = 'Settings';
}

$isActive = static function (string $item) use ($path): bool {
    if ($item === '/admin') {
        return $path === '/admin';
    }

    return str_starts_with($path, $item);
};
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
<body>
<a class="skip-link" href="#main">Skip to content</a>

<header class="topbar">
    <a class="topbar__brand" href="/admin"><?= $e($siteName) ?></a>

    <nav class="topbar__nav" aria-label="Main">
        <?php foreach ($navItems as $href => $label): ?>
            <a href="<?= $e($href) ?>"
               class="topbar__link<?= $isActive($href) ? ' is-active' : '' ?>"
               <?= $isActive($href) ? 'aria-current="page"' : '' ?>><?= $e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="topbar__account">
        <?php if ($actor !== null && $actor->authenticated()): ?>
            <form method="post" action="/admin/logout" class="inline">
                <input type="hidden" name="_token" value="<?= $e($view->get('csrf')) ?>">
                <button type="submit" class="link-button">Sign out</button>
            </form>
        <?php endif; ?>
    </div>
</header>

<main id="main" class="main">
    <?php if (is_array($flash)): ?>
        <p class="notice notice--<?= $e($flash['type'] ?? 'info') ?>" role="status">
            <?= $e($flash['message'] ?? '') ?>
        </p>
    <?php endif; ?>

    <?= $view->get('content', '') ?>
</main>
</body>
</html>
