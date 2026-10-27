<?php
/**
 * Archive template: home, category, tag, author and search all resolve here.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 * @var \CMS\Modules\Theme\Contracts\ViewRendererInterface $renderer
 */

$themeName = (string) $view->get('theme_name', 'default');

echo $renderer->partial($themeName, 'head', $view);
?>
<main class="site-main">
    <?= $renderer->partial($themeName, 'ad', $view->with(['ad_placement' => 'header'])) ?>
  <h1><?= $view->e($view->get('title', 'Archive')) ?></h1>

  <?php if ($view->get('term') !== null): ?>
    <p class="term-description"><?= $view->e($view->get('term')['description'] ?? '') ?></p>
  <?php endif; ?>

  <?php foreach ((array) $view->get('items', []) as $item): ?>
    <article class="card">
      <h2><a href="<?= $view->e($item['url'] ?? '#') ?>"><?= $view->e($item['title'] ?? '') ?></a></h2>
      <?php if (!empty($item['excerpt'])): ?>
        <p><?= $view->e($item['excerpt']) ?></p>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>

  <?php if ((int) $view->get('total_pages', 1) > 1): ?>
    <nav class="pagination">
      <span>Page <?= (int) $view->get('page', 1) ?> of <?= (int) $view->get('total_pages', 1) ?></span>
    </nav>
  <?php endif; ?>
</main>
<?php
echo $renderer->partial($themeName, 'footer', $view);
