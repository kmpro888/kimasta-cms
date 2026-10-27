<?php
/**
 * Fallback template: every other template in the hierarchy resolves here.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 * @var \CMS\Modules\Theme\Contracts\ViewRendererInterface $renderer
 */

$themeName = (string) $view->get('theme_name', 'default');

echo $renderer->partial($themeName, 'head', $view);
?>
<main class="site-main">
    <?= $renderer->partial($themeName, 'ad', $view->with(['ad_placement' => 'header'])) ?>
  <h1><?= $view->e($view->get('title', '')) ?></h1>

  <?php if ($view->get('notice') !== null): ?>
    <p class="notice"><?= $view->e($view->get('notice')) ?></p>
  <?php endif; ?>

  <?php foreach ((array) $view->get('items', []) as $item): ?>
    <article class="card">
      <h2>
        <a href="<?= $view->e($item['url'] ?? '#') ?>"><?= $view->e($item['title'] ?? '') ?></a>
      </h2>
      <?php if (!empty($item['excerpt'])): ?>
        <p><?= $view->e($item['excerpt']) ?></p>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</main>
<?php
echo $renderer->partial($themeName, 'footer', $view);
