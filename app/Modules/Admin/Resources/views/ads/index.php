<?php

declare(strict_types=1);

/**
 * Ad placements.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$ads = $view->get('ads', []);
$placements = $view->get('placements', []);
$unsupported = $view->get('unsupported', []);
$errors = $view->get('errors', []);
$values = $view->get('values', []);
$csrf = $view->get('csrf');

$error = static function (string $field) use ($errors): string {
    $list = $errors[$field] ?? [];

    return is_array($list) && $list !== [] ? (string) reset($list) : '';
};
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title">Advertising</h1>
        <p class="page-head__sub"><?= $e(count($ads)) ?> placement(s) on this site</p>
    </div>
</div>

<?php if ($ads === []): ?>
    <p class="empty">No ads yet.</p>
<?php else: ?>
    <table class="table">
        <thead>
        <tr>
            <th scope="col">Name</th>
            <th scope="col">Placement</th>
            <th scope="col">Priority</th>
            <th scope="col">Status</th>
            <th scope="col"><span class="visually-hidden">Actions</span></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($ads as $ad): ?>
            <tr>
                <td>
                    <strong><?= $e($ad->name) ?></strong>
                    <?php if ($ad->targetUrl !== null): ?>
                        <br><span class="muted"><?= $e($ad->targetUrl) ?></span>
                    <?php endif; ?>
                </td>
                <td class="muted"><?= $e($ad->placement) ?></td>
                <td class="muted"><?= $e($ad->priority) ?></td>
                <td>
                    <span class="badge badge--<?= $ad->isActive ? 'published' : 'trashed' ?>">
                        <?= $ad->isActive ? 'running' : 'off' ?>
                    </span>
                </td>
                <td>
                    <form method="post" action="/admin/ads/toggle" class="inline">
                        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="id" value="<?= $e($ad->id) ?>">
                        <button class="button button--small" type="submit">
                            <?= $ad->isActive ? 'Switch off' : 'Switch on' ?>
                        </button>
                    </form>
                    <form method="post" action="/admin/ads/delete" class="inline"
                          data-confirm="Delete this ad?">
                        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="id" value="<?= $e($ad->id) ?>">
                        <button class="button button--small button--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<form class="panel" method="post" action="/admin/ads">
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
    <h2 class="panel__title">Add an ad</h2>

    <div class="upload">
        <label class="field">
            <span class="field__label">Name</span>
            <input class="field__input" type="text" name="name" required
                   value="<?= $e($values['name'] ?? '') ?>">
            <?php if ($error('name') !== ''): ?>
                <span class="field__error"><?= $e($error('name')) ?></span>
            <?php endif; ?>
        </label>

        <label class="field">
            <span class="field__label">Placement</span>
            <select class="field__input" name="placement">
                <?php foreach ($placements as $placement): ?>
                    <option value="<?= $e($placement) ?>"
                        <?= ($values['placement'] ?? '') === $placement ? 'selected' : '' ?>>
                        <?= $e($placement) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span class="field__label">Priority</span>
            <input class="field__input" type="number" name="priority" min="0"
                   value="<?= $e($values['priority'] ?? 0) ?>">
            <span class="hint">Higher runs first when several ads share a placement.</span>
        </label>
    </div>

    <label class="field">
        <span class="field__label">Target URL</span>
        <input class="field__input" type="url" name="target_url"
               value="<?= $e($values['target_url'] ?? '') ?>" placeholder="https://…">
        <?php if ($error('target_url') !== ''): ?>
            <span class="field__error"><?= $e($error('target_url')) ?></span>
        <?php endif; ?>
    </label>

    <label class="field">
        <span class="field__label">Markup</span>
        <textarea class="field__input" name="body_html" rows="5" required
                  placeholder="&lt;a href=&quot;https://…&quot;&gt;Sponsor&lt;/a&gt;"><?= $e($values['body_html'] ?? '') ?></textarea>
        <span class="hint">
            Rendered as-is on every page in this placement. This is why the capability is
            administrator-only: whoever can type here can put markup on the public site.
        </span>
        <?php if ($error('body_html') !== ''): ?>
            <span class="field__error"><?= $e($error('body_html')) ?></span>
        <?php endif; ?>
    </label>

    <label class="check">
        <input type="checkbox" name="is_active" value="1"
            <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
        <span>Run it immediately</span>
    </label>

    <div class="actions">
        <button class="button button--primary" type="submit">Create ad</button>
    </div>
</form>

<?php if ($unsupported !== []): ?>
    <div class="panel">
        <h2 class="panel__title">Placements this theme does not print</h2>
        <p class="hint">
            These exist in the model and are not offered above. Saying so is the point: an option
            that silently does nothing is worse than an option that is missing.
        </p>
        <ul class="hint">
            <?php foreach ($unsupported as $placement => $reason): ?>
                <li><strong><?= $e($placement) ?></strong> — <?= $e($reason) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
