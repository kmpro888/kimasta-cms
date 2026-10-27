<?php

declare(strict_types=1);

/**
 * The revision history of one article.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$content = $view->get('content');
$revisions = $view->get('revisions', []);
$csrf = $view->get('csrf');
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title">Revisions</h1>
        <p class="page-head__sub"><?= $e($content->title) ?> &middot; <?= $e(count($revisions)) ?> saved version(s)</p>
    </div>

    <p class="page-head__back">
        <a href="/admin/content/<?= $e($content->id) ?>">&larr; Back to the article</a>
    </p>
</div>

<?php if ($revisions === []): ?>
    <p class="empty">No revisions yet. One is written every time the article is saved.</p>
<?php else: ?>
    <table class="table">
        <thead>
        <tr>
            <th scope="col">#</th>
            <th scope="col">Title at the time</th>
            <th scope="col">Saved</th>
            <th scope="col"><span class="visually-hidden">Actions</span></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($revisions as $revision): ?>
            <tr>
                <td class="muted"><?= $e($revision->revisionNumber) ?></td>
                <td><?= $e($revision->title) ?></td>
                <td class="muted"><?= $e($revision->createdAt->format('j M Y, H:i')) ?></td>
                <td>
                    <form method="post" action="/admin/content/<?= $e($content->id) ?>/revisions/restore"
                          class="inline"
                          data-confirm="Restore revision <?= $e($revision->revisionNumber) ?>? The current version is saved first.">
                        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="revision_id" value="<?= $e($revision->id) ?>">
                        <button class="button button--small" type="submit">Restore</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="hint">
        Restoring saves the live version first, so a restore is itself undoable.
    </p>
<?php endif; ?>
