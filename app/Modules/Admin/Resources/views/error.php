<?php

declare(strict_types=1);

/**
 * Error page.
 *
 * Standalone on purpose: an error must render even when no site or actor could be resolved,
 * so this file depends on nothing but the values it is handed.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($view->get('title')) ?></title>
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body class="is-error">
<main class="error-page">
    <p class="error-page__status"><?= $e($view->get('status')) ?></p>
    <h1 class="error-page__heading"><?= $e($view->get('heading')) ?></h1>
    <p class="error-page__message"><?= $e($view->get('message')) ?></p>
    <p><a class="button" href="/admin">Back to the dashboard</a></p>
</main>
</body>
</html>
