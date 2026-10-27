<?php

declare(strict_types=1);

/**
 * Comment moderation queue.
 *
 * Every action posts and redirects, so moderating a hundred comments is a series of clicks that
 * cannot be repeated by a refresh.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$items = $view->get('items', []);
$status = (string) $view->get('status', 'pending');
$titles = $view->get('titles', []);
$counts = $view->get('counts', []);
$csrf = $view->get('csrf');

$tabs = [
    'pending' => 'Pending',
    'approved' => 'Approved',
    'spam' => 'Spam',
    'trash' => 'Trash',
];

$formatDate = static fn (mixed $value): string => $value instanceof \DateTimeImmutable
    ? $value->format('j M Y, H:i')
    : '';
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title">Comments</h1>
        <p class="page-head__sub">
            <?= $e($counts['pending'] ?? 0) ?> waiting &middot;
            <?= $e($counts['approved'] ?? 0) ?> approved
        </p>
    </div>
</div>

<nav class="tabs" aria-label="Comment status">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="tabs__item<?= $key === $status ? ' is-active' : '' ?>"
           href="/admin/comments?status=<?= $e($key) ?>">
            <?= $e($label) ?> <span class="tabs__count"><?= $e($counts[$key] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($items === []): ?>
    <p class="empty">Nothing here. The <?= $e($status) ?> queue is empty.</p>
<?php else: ?>
    <ul class="comment-list">
        <?php foreach ($items as $item): ?>
            <?php $contentId = (int) $item->contentId; ?>
            <li class="comment-item">
                <div class="comment-item__head">
                    <strong><?= $e($item->authorName) ?></strong>
                    <span class="comment-item__on">
                        on
                        <a href="/admin/content/<?= $e($contentId) ?>">
                            <?= $e($titles[$contentId] ?? ('#' . $contentId)) ?>
                        </a>
                    </span>
                    <span class="comment-item__when"><?= $e($formatDate($item->createdAt)) ?></span>
                </div>

                <p class="comment-item__body"><?= nl2br($e($item->body), false) ?></p>

                <div class="comment-item__actions">
                    <?php if ($item->status !== 'approved'): ?>
                        <form method="post" action="/admin/comments/<?= $e($item->id) ?>/approve" class="inline">
                            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="status" value="<?= $e($status) ?>">
                            <button class="button button--small" type="submit">Approve</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($item->status !== 'spam'): ?>
                        <form method="post" action="/admin/comments/<?= $e($item->id) ?>/spam" class="inline">
                            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="status" value="<?= $e($status) ?>">
                            <button class="button button--small" type="submit">Spam</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($item->status !== 'trash'): ?>
                        <form method="post" action="/admin/comments/<?= $e($item->id) ?>/trash" class="inline">
                            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="status" value="<?= $e($status) ?>">
                            <button class="button button--small" type="submit">Trash</button>
                        </form>
                    <?php endif; ?>

                    <form method="post" action="/admin/comments/<?= $e($item->id) ?>/delete" class="inline"
                          data-confirm="Delete this comment permanently?">
                        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="status" value="<?= $e($status) ?>">
                        <button class="button button--small button--danger" type="submit">Delete</button>
                    </form>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
