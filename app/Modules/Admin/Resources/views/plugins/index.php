<?php

declare(strict_types=1);

/**
 * Plugins: discovered, installed, and switched on for this site.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$rows = $view->get('rows', []);
$csrf = $view->get('csrf');
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title">Plugins</h1>
        <p class="page-head__sub"><?= $e(count($rows)) ?> found on disk</p>
    </div>
</div>

<?php if ($rows === []): ?>
    <p class="empty">No plugin directories found.</p>
<?php else: ?>
    <ul class="plugin-list">
        <?php foreach ($rows as $row): ?>
            <?php $manifest = $row['manifest']; ?>
            <li class="plugin-item">
                <div class="plugin-item__head">
                    <strong><?= $e($manifest->title) ?></strong>
                    <span class="muted"><code><?= $e($manifest->name) ?></code> <?= $e($manifest->version) ?></span>

                    <?php if ($row['outdated']): ?>
                        <span class="badge badge--published">Update available: <?= $e((string) $row['installedVersion']) ?> &rarr; <?= $e($manifest->version) ?></span>
                    <?php endif; ?>

                    <?php if ($row['activeHere']): ?>
                        <span class="badge badge--published">Active here</span>
                    <?php elseif ($row['installed']): ?>
                        <span class="badge">Installed, off</span>
                    <?php else: ?>
                        <span class="badge badge--trashed">Not installed</span>
                    <?php endif; ?>

                    <?php if ($row['activeSites'] > 1): ?>
                        <span class="muted">on <?= $e($row['activeSites']) ?> sites</span>
                    <?php endif; ?>
                </div>

                <?php if ($manifest->description !== null && $manifest->description !== ''): ?>
                    <p class="plugin-item__description"><?= $e($manifest->description) ?></p>
                <?php endif; ?>

                <p class="plugin-item__meta">
                    <?php if ($manifest->author !== null && $manifest->author !== ''): ?>
                        by <?= $e($manifest->author) ?>
                    <?php endif; ?>
                    <?php if ($manifest->requiresPlugins !== []): ?>
                        &middot; requires <?= $e(implode(', ', $manifest->requiresPlugins)) ?>
                    <?php endif; ?>
                </p>

                <div class="plugin-item__actions">
                    <?php if (!$row['installed']): ?>
                        <form method="post" action="/admin/plugins/install" class="inline">
                            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="name" value="<?= $e($manifest->name) ?>">
                            <button class="button button--small" type="submit">Install</button>
                        </form>
                    <?php else: ?>
                        <?php if ($row['outdated']): ?>
                            <form method="post" action="/admin/plugins/update" class="inline"
                                  data-confirm="Update <?= $e($manifest->title) ?> from <?= $e((string) $row['installedVersion']) ?> to <?= $e($manifest->version) ?>? Its new migrations run first.">
                                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="name" value="<?= $e($manifest->name) ?>">
                                <button class="button button--small button--primary" type="submit">Update</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($row['activeHere']): ?>
                            <form method="post" action="/admin/plugins/deactivate" class="inline">
                                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="name" value="<?= $e($manifest->name) ?>">
                                <button class="button button--small" type="submit">Switch off for this site</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="/admin/plugins/activate" class="inline">
                                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="name" value="<?= $e($manifest->name) ?>">
                                <button class="button button--small" type="submit">Switch on for this site</button>
                            </form>
                        <?php endif; ?>

                        <form method="post" action="/admin/plugins/uninstall" class="inline"
                              data-confirm="Uninstall? The plugin stops loading everywhere. Tables it created stay in the database.">
                            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="name" value="<?= $e($manifest->name) ?>">
                            <button class="button button--small button--danger" type="submit">Uninstall</button>
                        </form>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<p class="hint">
    Installing is an operator action for the whole installation, because it runs the plugin's
    migrations. Switching on is per site, so one tenant cannot change another tenant's schema or
    behaviour.
</p>
