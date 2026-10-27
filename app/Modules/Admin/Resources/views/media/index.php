<?php

declare(strict_types=1);

/**
 * Media library, and the picker it doubles as.
 *
 * With no JavaScript, choosing an image is a form post that names its target: the picker
 * knows whether it was opened for the featured image or for a specific block, and the apply
 * button posts to the right action. `pick` is what carries that context through the round
 * trip.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$items = $view->get('items', []);
$pick = (string) $view->get('pick', '');
$contentId = (int) $view->get('contentId', 0);
$blockIndex = (int) $view->get('blockIndex', 0);
$canUpload = (bool) $view->get('canUpload', false);
$canDelete = (bool) $view->get('canDelete', false);
$uploadError = $view->get('uploadError');
$csrf = $view->get('csrf');

$picking = $pick !== '' && $contentId > 0;
$editorUrl = '/admin/content/' . $contentId;

$action = match ($pick) {
    'featured' => $editorUrl . '/featured',
    'block' => $editorUrl . '/block-image',
    default => '',
};
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title">Media</h1>
        <p class="page-head__sub">
            <?php if ($picking): ?>
                Choose an image for
                <?= $pick === 'block' ? 'block ' . $e($blockIndex + 1) : 'the featured image' ?>
            <?php else: ?>
                <?= $e(count($items)) ?> file(s) on this page
            <?php endif; ?>
        </p>
    </div>

    <?php if ($picking): ?>
        <p class="page-head__back"><a href="<?= $e($editorUrl) ?>">&larr; Back to the article</a></p>
    <?php endif; ?>
</div>

<?php if (is_string($uploadError) && $uploadError !== ''): ?>
    <p class="notice notice--error" role="alert"><?= $e($uploadError) ?></p>
<?php endif; ?>

<?php if ($canUpload): ?>
    <form class="panel" method="post" action="/admin/media" enctype="multipart/form-data">
        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="pick" value="<?= $e($pick) ?>">
        <input type="hidden" name="content_id" value="<?= $e($contentId) ?>">
        <input type="hidden" name="index" value="<?= $e($blockIndex) ?>">

        <h2 class="panel__title">Upload</h2>

        <div class="upload">
            <label class="field">
                <span class="field__label">File</span>
                <input class="field__input" type="file" name="file" required>
            </label>

            <label class="field">
                <span class="field__label">Alternative text</span>
                <input class="field__input" type="text" name="alt_text"
                       placeholder="What the image shows, for screen readers">
            </label>

            <button class="button button--primary" type="submit">Upload</button>
        </div>
    </form>
<?php endif; ?>

<?php if ($items === []): ?>
    <p class="empty">No images yet.<?= $canUpload ? ' Upload one above.' : '' ?></p>
<?php else: ?>
    <ul class="media-grid">
        <?php foreach ($items as $item): ?>
            <li class="media-card">
                <div class="media-card__frame">
                    <?php if ($item->isImage()): ?>
                        <img class="media-card__image" src="<?= $e($item->url()) ?>" alt="<?= $e($item->altText ?? '') ?>"
                             loading="lazy" width="<?= $e($item->width ?? 320) ?>" height="<?= $e($item->height ?? 240) ?>">
                    <?php else: ?>
                        <span class="media-card__file"><?= $e(strtoupper($item->extension)) ?></span>
                    <?php endif; ?>
                </div>

                <div class="media-card__meta">
                    <span class="media-card__name" title="<?= $e($item->originalName ?? '') ?>">
                        <?= $e($item->originalName ?? basename($item->path)) ?>
                    </span>
                    <span class="media-card__size">
                        <?= $e($item->filesize < 1024 ? $item->filesize . ' B' : number_format($item->filesize / 1024, 0) . ' KB') ?>
                        <?php if ($item->width !== null && $item->height !== null): ?>
                            &middot; <?= $e($item->width) ?>x<?= $e($item->height) ?>
                        <?php endif; ?>
                    </span>
                </div>

                <?php if ($picking && $item->isImage()): ?>
                    <form method="post" action="<?= $e($action) ?>" class="media-card__form">
                        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="media_id" value="<?= $e($item->id) ?>">
                        <?php if ($pick === 'block'): ?>
                            <input type="hidden" name="index" value="<?= $e($blockIndex) ?>">
                        <?php endif; ?>
                        <button class="button button--small button--block" type="submit">Use this image</button>
                    </form>
                <?php elseif ($canDelete): ?>
                    <form method="post" action="/admin/media/<?= $e($item->id) ?>/delete" class="media-card__form">
                        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                        <button class="button button--small button--danger button--block" type="submit">Delete</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
