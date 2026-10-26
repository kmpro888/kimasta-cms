<?php
/**
 * Single content template.
 *
 * `content_html` was compiled and sanitized at save time by the block engine, so it
 * is printed as-is here. Re-escaping it would display raw markup to the reader.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 * @var \CMS\Modules\Theme\Contracts\ViewRendererInterface $renderer
 */

$themeName = (string) $view->get('theme_name', 'default');

echo $renderer->partial($themeName, 'head', $view);
?>
<main class="site-main">
  <article class="content">
    <h1><?= $view->e($view->get('title', '')) ?></h1>

    <?php if ($view->get('published_at') !== null): ?>
      <p class="meta">
        <time datetime="<?= $view->e($view->get('published_at')) ?>">
          <?= $view->e($view->get('published_at')) ?>
        </time>
      </p>
    <?php endif; ?>

    <?php if ($view->get('breadcrumbs_html') !== null): ?>
      <?= (string) $view->get('breadcrumbs_html') ?>
    <?php endif; ?>

    <?php
    /*
     * Ad slots. The service has already decided which ads are eligible for this page and
     * rendered them, so the template only prints — it never queries and never applies
     * targeting rules itself.
     */
    $ads = (array) $view->get('ads', []);
    ?>
    <?php if (($ads['before_article'] ?? '') !== ''): ?>
      <div class="ad-slot ad-before"><?= (string) $ads['before_article'] ?></div>
    <?php endif; ?>

    <div class="content-body"><?= (string) $view->get('content_html', '') ?></div>

    <?php if (($ads['after_article'] ?? '') !== ''): ?>
      <div class="ad-slot ad-after"><?= (string) $ads['after_article'] ?></div>
    <?php endif; ?>

    <?php if (!empty($view->get('terms'))): ?>
      <ul class="terms">
        <?php foreach ((array) $view->get('terms') as $term): ?>
          <li><?= $view->e($term['name'] ?? '') ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </article>
</main>
<?php
echo $renderer->partial($themeName, 'footer', $view);
