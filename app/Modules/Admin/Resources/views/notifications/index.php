<?php

declare(strict_types=1);

/**
 * The signed-in person's in-app notifications.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$items = $view->get('items', []);
$unread = (int) $view->get('unread', 0);
$unreadOnly = (bool) $view->get('unreadOnly', false);
$csrf = $view->get('csrf');

$formatDate = static fn (mixed $value): string => $value instanceof \DateTimeImmutable
    ? $value->format('j M Y, H:i')
    : '';
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title">Notifications</h1>
        <p class="page-head__sub">
            <?= $e($unread) ?> unread
            <?php if ($view->get('canManagePreferences')): ?>
                &middot; <a href="/admin/notifications/preferences">Email and in-app switches</a>
            <?php endif; ?>
        </p>
    </div>

    <?php if ($unread > 0): ?>
        <form method="post" action="/admin/notifications/read-all">
            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="filter" value="<?= $unreadOnly ? 'unread' : '' ?>">
            <button class="button" type="submit">Mark all as read</button>
        </form>
    <?php endif; ?>
</div>

<nav class="tabs" aria-label="Notification filter">
    <a class="tabs__item<?= $unreadOnly ? '' : ' is-active' ?>" href="/admin/notifications">All</a>
    <a class="tabs__item<?= $unreadOnly ? ' is-active' : '' ?>" href="/admin/notifications?filter=unread">Unread</a>
</nav>

<?php if ($items === []): ?>
    <p class="empty">
        <?= $unreadOnly ? 'Nothing unread.' : 'No notifications yet. They arrive when an article you wrote moves through the workflow.' ?>
    </p>
<?php else: ?>
    <ul class="notice-list">
        <?php foreach ($items as $item): ?>
            <li class="notice-item<?= $item->isRead() ? '' : ' notice-item--unread' ?>">
                <div class="notice-item__head">
                    <strong><?= $e($item->title) ?></strong>
                    <span class="notice-item__when"><?= $e($formatDate($item->createdAt)) ?></span>
                </div>

                <p class="notice-item__body"><?= $e($item->body) ?></p>

                <div class="notice-item__actions">
                    <?php if ($item->contentId !== null): ?>
                        <a class="button button--small" href="/admin/content/<?= $e($item->contentId) ?>">
                            Open the article
                        </a>
                    <?php endif; ?>

                    <?php if (!$item->isRead()): ?>
                        <form method="post" action="/admin/notifications/<?= $e($item->id) ?>/read" class="inline">
                            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="filter" value="<?= $unreadOnly ? 'unread' : '' ?>">
                            <button class="button button--small" type="submit">Mark as read</button>
                        </form>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
