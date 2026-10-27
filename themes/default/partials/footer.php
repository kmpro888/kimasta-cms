<?php
/**
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */
?>
<?php
// Rendered inline rather than through a nested partial: a partial receives only `$view`, so it
// cannot call the renderer. The condition mirrors `partials/ad.php`.
$footerAds = $view->get('ads', []);
$footerSlot = is_array($footerAds) ? ($footerAds['footer'] ?? '') : '';
?>
<?php if (is_string($footerSlot) && trim($footerSlot) !== ''): ?>
  <div class="ad-slot ad-slot--footer" data-ad-placement="footer"><?= $footerSlot ?></div>
<?php endif; ?>
<footer class="site-footer">
  <p><?= $view->e($view->get('site_name', '')) ?></p>
</footer>
</body>
</html>
