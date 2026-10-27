<?php

declare(strict_types=1);

/**
 * Site settings.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$values = $view->get('values', []);
$errors = $view->get('errors', []);
$zones = $view->get('zones', []);
$site = $view->get('site');
$themes = $view->get('themes', []);
$activeTheme = (string) $view->get('activeTheme', '');
$canManageTheme = (bool) $view->get('canManageTheme', false);
$csrf = $view->get('csrf');

$error = static function (string $field) use ($errors): string {
    $list = $errors[$field] ?? [];
    $first = is_array($list) && $list !== [] ? (string) reset($list) : '';

    return $first;
};
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title">Site settings</h1>
        <p class="page-head__sub">
            <code><?= $e($site->host()) ?></code> &middot; slug <code><?= $e($site->slug()) ?></code>
        </p>
    </div>
</div>

<div class="editor">
    <form class="editor__main" method="post" action="/admin/settings">
        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">

        <label class="field">
            <span class="field__label">Site name</span>
            <input class="field__input" type="text" name="name" required
                   value="<?= $e($values['name'] ?? '') ?>">
            <span class="hint">Shown in the header and in notification emails.</span>
            <?php if ($error('name') !== ''): ?>
                <span class="field__error"><?= $e($error('name')) ?></span>
            <?php endif; ?>
        </label>

        <label class="field">
            <span class="field__label">Domain</span>
            <input class="field__input" type="text" value="<?= $e($site->host()) ?>" readonly>
            <span class="hint">
                Read-only here on purpose. Moving a site to another domain must move its domain
                mapping and rebuild the compiled map, and the old domain stops resolving at once.
            </span>
        </label>

        <label class="field">
            <span class="field__label">Base URL</span>
            <input class="field__input" type="url" name="base_url"
                   value="<?= $e($values['base_url'] ?? '') ?>" placeholder="https://example.com">
            <span class="hint">Used for canonical URLs and absolute links.</span>
            <?php if ($error('base_url') !== ''): ?>
                <span class="field__error"><?= $e($error('base_url')) ?></span>
            <?php endif; ?>
        </label>

        <label class="field">
            <span class="field__label">Default locale</span>
            <input class="field__input" type="text" name="default_locale" required
                   value="<?= $e($values['default_locale'] ?? '') ?>" placeholder="en">
            <?php if ($error('default_locale') !== ''): ?>
                <span class="field__error"><?= $e($error('default_locale')) ?></span>
            <?php endif; ?>
        </label>

        <label class="field">
            <span class="field__label">Timezone</span>
            <select class="field__input" name="timezone">
                <?php foreach ($zones as $zone): ?>
                    <option value="<?= $e($zone) ?>"<?= ($values['timezone'] ?? '') === $zone ? ' selected' : '' ?>>
                        <?= $e($zone) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="hint">Every rendered date uses it, including the scheduler.</span>
            <?php if ($error('timezone') !== ''): ?>
                <span class="field__error"><?= $e($error('timezone')) ?></span>
            <?php endif; ?>
        </label>

        <p class="hint">Saving clears this site's page cache, because every page carries at least one of these values.</p>

        <div class="actions">
            <button class="button button--primary" type="submit">Save settings</button>
        </div>
    </form>

    <?php if ($canManageTheme): ?>
        <aside class="editor__side">
            <form class="panel" method="post" action="/admin/settings/theme">
                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                <h2 class="panel__title">Theme</h2>

                <label class="field">
                    <span class="field__label">Active theme</span>
                    <select class="field__input" name="theme">
                        <?php foreach ($themes as $theme): ?>
                            <option value="<?= $e($theme) ?>"<?= $theme === $activeTheme ? ' selected' : '' ?>>
                                <?= $e($theme) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button class="button button--small button--block" type="submit">Switch theme</button>

                <p class="hint">Switching flushes the site cache; content is untouched.</p>
            </form>
        </aside>
    <?php endif; ?>
</div>
