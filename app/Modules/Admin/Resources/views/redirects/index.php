<?php

declare(strict_types=1);

/**
 * Redirects for this site: the list, and the form that adds one.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$items = $view->get('items', []);
$error = $view->get('error');
$values = $view->get('values', []);
$statuses = $view->get('statuses', [301]);
$csrf = $view->get('csrf');
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title">Redirects</h1>
        <p class="page-head__sub"><?= $e(count($items)) ?> redirect(s) on this site</p>
    </div>
</div>

<?php if (is_string($error) && $error !== ''): ?>
    <p class="notice notice--error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>

<form class="panel" method="post" action="/admin/redirects">
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
    <h2 class="panel__title">Add a redirect</h2>

    <div class="upload">
        <label class="field">
            <span class="field__label">Old path</span>
            <input class="field__input" type="text" name="source_path" required
                   value="<?= $e($values['source_path'] ?? '') ?>" placeholder="/old-url">
        </label>

        <label class="field">
            <span class="field__label">Goes to</span>
            <input class="field__input" type="text" name="target_url" required
                   value="<?= $e($values['target_url'] ?? '') ?>" placeholder="/new-url or https://...">
        </label>

        <label class="field">
            <span class="field__label">Code</span>
            <select class="field__input" name="status_code">
                <?php foreach ($statuses as $code): ?>
                    <option value="<?= $e($code) ?>"<?= (int) ($values['status_code'] ?? 301) === $code ? ' selected' : '' ?>>
                        <?= $e($code) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <button class="button button--primary" type="submit">Add</button>
    </div>

    <p class="hint">
        The old path is matched exactly, without a query string. A relative target keeps visitors on
        this site; a protocol-relative one (<code>//host</code>) is refused because it is an off-site
        redirect in disguise.
    </p>
</form>

<?php if ($items === []): ?>
    <p class="empty">No redirects yet.</p>
<?php else: ?>
    <table class="table">
        <thead>
        <tr>
            <th scope="col">Old path</th>
            <th scope="col">Goes to</th>
            <th scope="col">Code</th>
            <th scope="col">Hits</th>
            <th scope="col"><span class="visually-hidden">Actions</span></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <?php
            $row = is_array($item) ? $item : [];
            $source = (string) ($row['source_path'] ?? '');
            ?>
            <tr>
                <td><code><?= $e($source) ?></code></td>
                <td><code><?= $e($row['target_url'] ?? '') ?></code></td>
                <td class="muted"><?= $e($row['status_code'] ?? '') ?></td>
                <td class="muted"><?= $e($row['hit_count'] ?? 0) ?></td>
                <td>
                    <form method="post" action="/admin/redirects/delete" class="inline">
                        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="source_path" value="<?= $e($source) ?>">
                        <button class="button button--small button--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<div class="panel">
    <h2 class="panel__title">Import a CSV</h2>
    <p class="hint">
        Columns: <code>source, target, status</code>. A header row is skipped. Rows that are not a
        valid redirect are reported, not silently dropped.
    </p>

    <form method="post" action="/admin/redirects/import" enctype="multipart/form-data">
        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
        <label class="field">
            <span class="field__label">CSV file</span>
            <input class="field__input" type="file" name="csv" accept=".csv,text/csv" required>
        </label>
        <div class="actions">
            <button class="button" type="submit">Import</button>
        </div>
    </form>
</div>

<p class="hint">
    <?= $e((int) $view->get('pending', 0)) ?> hit(s) counted and waiting for the cron flush.
    Hits are written to a file per visit and folded into the totals here by
    <code>bin/cron.php</code> — a database write on every redirect is exactly the load this project
    does not take.
</p>
