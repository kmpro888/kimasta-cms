<?php

declare(strict_types=1);

/**
 * The audit trail: append-only, read-only here.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$entries = $view->get('entries', []);
$total = (int) $view->get('total', 0);
$page = (int) $view->get('page', 1);
$pages = (int) $view->get('pages', 1);
$action = (string) $view->get('action', '');

$link = static function (int $target) use ($action): string {
    $query = ['page' => $target];

    if ($action !== '') {
        $query['action'] = $action;
    }

    return '/admin/audit?' . http_build_query($query);
};
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title">Audit log</h1>
        <p class="page-head__sub">
            <?= $e($total) ?> recorded action(s). Append-only: there is no edit and no delete.
        </p>
    </div>
</div>

<form class="panel" method="get" action="/admin/audit">
    <label class="field">
        <span class="field__label">Filter by action</span>
        <input class="field__input" type="text" name="action" value="<?= $e($action) ?>"
               placeholder="user. — a trailing dot matches every action in that group">
        <span class="hint">Exact names work too: <code>settings.updated</code>, <code>plugin.activated</code>.</span>
    </label>
    <div class="actions">
        <button class="button" type="submit">Filter</button>
    </div>
</form>

<table class="table">
    <thead>
    <tr>
        <th scope="col">When (UTC)</th>
        <th scope="col">Who</th>
        <th scope="col">Action</th>
        <th scope="col">Subject</th>
        <th scope="col">Details</th>
    </tr>
    </thead>
    <tbody>
    <?php if ($entries === []): ?>
        <tr>
            <td colspan="5" class="muted">Nothing recorded yet.</td>
        </tr>
    <?php endif; ?>
    <?php foreach ($entries as $entry): ?>
        <tr>
            <td class="muted"><?= $e($entry->createdAt->format('Y-m-d H:i:s')) ?></td>
            <td><?= $e($entry->actorName) ?><?php if ($entry->actorId === null): ?>
                    <span class="badge">system</span><?php endif; ?></td>
            <td><code><?= $e($entry->action) ?></code></td>
            <td class="muted">
                <?php if ($entry->subjectType !== null): ?>
                    <?= $e($entry->subjectType) ?><?php if ($entry->subjectId !== null): ?>
                        #<?= $e($entry->subjectId) ?><?php endif; ?>
                <?php else: ?>
                    —
                <?php endif; ?>
            </td>
            <td class="muted">
                <?php if ($entry->context !== []): ?>
                    <code><?= $e(json_encode($entry->context, JSON_UNESCAPED_SLASHES)) ?></code>
                <?php else: ?>
                    —
                <?php endif; ?>
                <?php if ($entry->ip !== null): ?>
                    <br><span class="muted"><?= $e($entry->ip) ?></span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($pages > 1): ?>
    <nav class="pagination" aria-label="Audit pages">
        <?php if ($page > 1): ?>
            <a class="button button--small" href="<?= $e($link($page - 1)) ?>">Previous</a>
        <?php endif; ?>
        <span class="muted">Page <?= $e($page) ?> of <?= $e($pages) ?></span>
        <?php if ($page < $pages): ?>
            <a class="button button--small" href="<?= $e($link($page + 1)) ?>">Next</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
