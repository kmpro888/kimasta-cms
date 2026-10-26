<?php
/**
 * 404 template.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 * @var \CMS\Modules\Theme\Contracts\ViewRendererInterface $renderer
 */

$themeName = (string) $view->get('theme_name', 'default');

echo $renderer->partial($themeName, 'head', $view);
?>
<main class="site-main">
  <h1><?= $view->e($view->get('title', 'Not found')) ?></h1>
  <p><?= $view->e($view->get('notice', 'The page you are looking for does not exist.')) ?></p>
  <p><a href="/"><?= $view->e($view->get('home_label', 'Back to home')) ?></a></p>
</main>
<?php
echo $renderer->partial($themeName, 'footer', $view);
