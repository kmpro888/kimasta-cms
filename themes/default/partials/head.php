<?php
/**
 * Shared document head.
 *
 * Available: $view (ViewModel), $renderer (ViewRendererInterface).
 * SEO is rendered centrally by the SEO service and passed in as `seo_html`; this
 * template prints it and never builds its own canonical or robots value.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 * @var \CMS\Modules\Theme\Contracts\ViewRendererInterface $renderer
 */

$title = (string) $view->get('title', 'Untitled');
$seoHtml = (string) $view->get('seo_html', '');
$schemaHtml = (string) $view->get('seo_schema', '');
$analyticsHtml = (string) $view->get('analytics_html', '');
$themeName = (string) $view->get('theme_name', 'default');
?>
<!DOCTYPE html>
<html lang="<?= $view->e($view->get('locale', 'en')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($seoHtml !== ''): ?>
<?= $seoHtml ?>
<?php else: ?>
<title><?= $view->e($title) ?></title>
<?php endif; ?>
<?php if ($schemaHtml !== ''): ?>
<?= $schemaHtml ?>
<?php endif; ?>
<link rel="stylesheet" href="/themes/<?= $view->e($themeName) ?>/assets/theme.css">
<?php if ($analyticsHtml !== ''): ?>
<?php
// Raw by design: the analytics service builds a <script> tag, so escaping it
// would print markup instead of running the tracker.
?>
<?= $analyticsHtml ?>
<?php endif; ?>
</head>
<body>
