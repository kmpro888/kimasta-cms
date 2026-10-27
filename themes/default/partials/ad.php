<?php

declare(strict_types=1);

/**
 * One advertising placement.
 *
 * `ad_placement` names the slot; the markup was already rendered by the advertising service and is
 * printed as-is, because it is operator-authored HTML. An empty slot prints nothing at all, not an
 * empty div: a wrapper with no ad would still take space.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$placement = (string) $view->get('ad_placement', '');
$ads = $view->get('ads', []);

if (!is_array($ads) || $placement === '') {
    return;
}

$markup = $ads[$placement] ?? '';

if (!is_string($markup) || trim($markup) === '') {
    return;
}
?>
<div class="ad-slot ad-slot--<?= $e($placement) ?>" data-ad-placement="<?= $e($placement) ?>">
    <?= $markup ?>
</div>
