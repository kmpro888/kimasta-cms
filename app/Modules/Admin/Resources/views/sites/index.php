<?php

declare(strict_types=1);

/**
 * Sites and their domains.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$rows = $view->get('rows', []);
$errors = $view->get('errors', []);
$minimum = (int) $view->get('minimum', 8);
$csrf = $view->get('csrf');

$error = static function (string $field) use ($errors): string {
    $message = $errors[$field] ?? '';

    return is_string($message) ? $message : '';
};
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title">Sites</h1>
        <p class="page-head__sub"><?= $e(count($rows)) ?> site(s) on this installation</p>
    </div>
</div>

<table class="table">
    <thead>
    <tr>
        <th scope="col">Site</th>
        <th scope="col">Domains</th>
        <th scope="col">Locale</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <?php $site = $row['site']; ?>
        <tr>
            <td>
                <strong><?= $e($site->name()) ?></strong><br>
                <span class="muted"><code><?= $e($site->slug()) ?></code></span>
            </td>
            <td>
                <?php foreach ($row['domains'] as $domain): ?>
                    <div class="domain-row">
                        <code><?= $e($domain) ?></code>
                        <?php if ($domain === $site->host()): ?>
                            <span class="badge">primary</span>
                        <?php else: ?>
                            <form method="post" action="/admin/sites/domain/remove" class="inline"
                                  data-confirm="Remove <?= $e($domain) ?> from <?= $e($site->name()) ?>?">
                                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="site_id" value="<?= $e($site->id()) ?>">
                                <input type="hidden" name="domain" value="<?= $e($domain) ?>">
                                <button class="button button--small button--danger" type="submit">Remove</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <form method="post" action="/admin/sites/domain" class="inline">
                    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="site_id" value="<?= $e($site->id()) ?>">
                    <input class="field__input" type="text" name="domain" placeholder="alias.example.com"
                           aria-label="Additional domain for <?= $e($site->name()) ?>" required>
                    <button class="button button--small" type="submit">Add domain</button>
                </form>
            </td>
            <td class="muted"><?= $e($site->defaultLocale()) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<form class="panel" method="post" action="/admin/sites">
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
    <h2 class="panel__title">Add a site</h2>

    <div class="upload">
        <label class="field">
            <span class="field__label">Name</span>
            <input class="field__input" type="text" name="name" required value="">
            <?php if ($error('name') !== ''): ?><span class="field__error"><?= $e($error('name')) ?></span><?php endif; ?>
        </label>

        <label class="field">
            <span class="field__label">Slug</span>
            <input class="field__input" type="text" name="slug" required placeholder="second-site"
                   pattern="[a-z0-9-]+">
            <?php if ($error('slug') !== ''): ?><span class="field__error"><?= $e($error('slug')) ?></span><?php endif; ?>
        </label>

        <label class="field">
            <span class="field__label">Primary domain</span>
            <input class="field__input" type="text" name="domain" required placeholder="example.com">
            <?php if ($error('domain') !== ''): ?><span class="field__error"><?= $e($error('domain')) ?></span><?php endif; ?>
        </label>
    </div>

    <div class="upload">
        <label class="field">
            <span class="field__label">Locale</span>
            <input class="field__input" type="text" name="default_locale" value="en">
        </label>

        <label class="field">
            <span class="field__label">Timezone</span>
            <input class="field__input" type="text" name="timezone" value="UTC">
        </label>
    </div>

    <h3 class="panel__title">First administrator</h3>
    <p class="hint">
        A site nobody can sign in to is not usable. This creates its first administrator, who signs
        in at the new domain — accounts belong to one site, so there is no switching between them.
    </p>

    <div class="upload">
        <label class="field">
            <span class="field__label">Username</span>
            <input class="field__input" type="text" name="admin_username" required>
            <?php if ($error('admin_username') !== ''): ?><span class="field__error"><?= $e($error('admin_username')) ?></span><?php endif; ?>
        </label>

        <label class="field">
            <span class="field__label">Email</span>
            <input class="field__input" type="email" name="admin_email" required>
        </label>

        <label class="field">
            <span class="field__label">Password</span>
            <input class="field__input" type="password" name="admin_password" required
                   minlength="<?= $e($minimum) ?>" autocomplete="new-password">
            <?php if ($error('admin_password') !== ''): ?><span class="field__error"><?= $e($error('admin_password')) ?></span><?php endif; ?>
        </label>
    </div>

    <div class="actions">
        <button class="button button--primary" type="submit">Create site</button>
    </div>
</form>
