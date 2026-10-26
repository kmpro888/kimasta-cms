<?php

declare(strict_types=1);

/**
 * Landing screen.
 *
 * Three lists: what this person is writing, what they have published, and -- only for
 * someone who can act on it -- the review queue.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$drafts = $view->get('drafts', []);
$published = $view->get('published', []);
$queue = $view->get('queue', []);
$formatDate = static fn (?object $value): string => $value instanceof \DateTimeImmutable
    ? $value->format('j M Y')
    : '';
?>
<div class="page-head">
    <h1 class="page-head__title">Dashboard</h1>

    <?php if ($view->get('canCreate')): ?>
        <a class="button button--primary" href="/admin/content/new">New article</a>
    <?php endif; ?>
</div>

<section class="panel">
    <h2 class="panel__title">Drafts</h2>

    <?php if ($drafts === []): ?>
        <p class="empty">Nothing in progress. A draft you start appears here.</p>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($drafts as $item): ?>
                <li class="list__item">
                    <a class="list__title" href="/admin/content/<?= $e($item->id) ?>"><?= $e($item->title) ?></a>
                    <span class="badge badge--<?= $e($item->status) ?>"><?= $e($item->status) ?></span>
                    <span class="list__meta">edited <?= $e($formatDate($item->updatedAt)) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="panel__more"><a href="/admin/content?status=draft">All drafts</a></p>
    <?php endif; ?>
</section>

<?php if ($view->get('canReview')): ?>
    <section class="panel">
        <h2 class="panel__title">Waiting for review</h2>

        <?php if ($queue === []): ?>
            <p class="empty">Nothing is waiting. The queue is clear.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($queue as $item): ?>
                    <li class="list__item">
                        <a class="list__title" href="/admin/content/<?= $e($item->id) ?>/review"><?= $e($item->title) ?></a>
                        <span class="list__meta">submitted <?= $e($formatDate($item->updatedAt)) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="panel">
    <h2 class="panel__title">Published</h2>

    <?php if ($published === []): ?>
        <p class="empty">Nothing published yet.</p>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($published as $item): ?>
                <li class="list__item">
                    <a class="list__title" href="/admin/content/<?= $e($item->id) ?>"><?= $e($item->title) ?></a>
                    <span class="list__meta"><?= $e($formatDate($item->publishedAt)) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
