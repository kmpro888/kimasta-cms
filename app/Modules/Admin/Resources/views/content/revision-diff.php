<?php

declare(strict_types=1);

/**
 * A revision against the one before it.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$content = $view->get('content');
$revision = $view->get('revision');
$diff = $view->get('diff', ['title' => [], 'excerpt' => [], 'blocks' => [], 'changed' => false]);
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title">Compare revision <?= $e($revision->revisionNumber) ?></h1>
        <p class="page-head__sub">
            <?= $e($content->title) ?> &middot; saved
            <?= $e($revision->createdAt->format('Y-m-d H:i')) ?> UTC
        </p>
    </div>
    <div>
        <a class="button" href="/admin/content/<?= $e($content->id) ?>/revisions">&larr; All revisions</a>
        <a class="button" href="/admin/content/<?= $e($content->id) ?>">Open the article</a>
    </div>
</div>

<?php if (!$diff['changed']): ?>
    <p class="hint">
        Nothing changed between this revision and the one before it. That is worth knowing: an
        editor who saved without editing produces a revision that says so.
    </p>
<?php else: ?>
    <?php if ($diff['title']['from'] !== $diff['title']['to']): ?>
        <h2 class="panel__title">Title</h2>
        <table class="table">
            <thead>
            <tr>
                <th scope="col">Before</th>
                <th scope="col">After</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td class="diff-before"><del><?= $e($diff['title']['from']) ?></del></td>
                <td class="diff-after"><ins><?= $e($diff['title']['to']) ?></ins></td>
            </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($diff['excerpt']['from'] !== $diff['excerpt']['to']): ?>
        <h2 class="panel__title">Excerpt</h2>
        <table class="table">
            <thead>
            <tr>
                <th scope="col">Before</th>
                <th scope="col">After</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td class="diff-before"><del><?= $e($diff['excerpt']['from']) ?></del></td>
                <td class="diff-after"><ins><?= $e($diff['excerpt']['to']) ?></ins></td>
            </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($diff['blocks'] !== []): ?>
        <h2 class="panel__title">Content</h2>
        <?php foreach ($diff['blocks'] as $block): ?>
            <section class="panel">
                <p class="hint">
                    Block <?= $e($block['index'] + 1) ?> —
                    <strong><?= $e($block['status']) ?></strong>
                </p>
                <div class="diff-pair">
                    <div class="diff-before">
                        <h3 class="hint">Before</h3>
                        <?php if ($block['from'] === ''): ?>
                            <p class="muted">(nothing)</p>
                        <?php else: ?>
                            <del><?= nl2br($e($block['from']), false) ?></del>
                        <?php endif; ?>
                    </div>
                    <div class="diff-after">
                        <h3 class="hint">After</h3>
                        <?php if ($block['to'] === ''): ?>
                            <p class="muted">(nothing)</p>
                        <?php else: ?>
                            <ins><?= nl2br($e($block['to']), false) ?></ins>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>
