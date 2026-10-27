<?php

declare(strict_types=1);

/**
 * Notification switches, per site.
 *
 * One switch per event and channel, not per person: the service stores them for the tenant. That
 * is why this screen needs `settings.update` while the notification list does not.
 *
 * Every box is rendered, including the off ones. An unchecked box is not submitted, so the
 * controller writes every known event and channel from the form — otherwise turning something
 * off would be impossible.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$preferences = $view->get('preferences', []);
$events = $view->get('events', []);
$channels = $view->get('channels', []);
$csrf = $view->get('csrf');
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title">Notification settings</h1>
        <p class="page-head__sub">Which events reach which channel, for this site.</p>
    </div>

    <p class="page-head__back"><a href="/admin/notifications">&larr; Notifications</a></p>
</div>

<form class="panel" method="post" action="/admin/notifications/preferences">
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">

    <table class="table">
        <thead>
        <tr>
            <th scope="col">Event</th>
            <?php foreach ($channels as $label): ?>
                <th scope="col"><?= $e($label) ?></th>
            <?php endforeach; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($events as $event => $label): ?>
            <tr>
                <td><?= $e($label) ?></td>
                <?php foreach (array_keys($channels) as $channel): ?>
                    <?php $checked = (bool) ($preferences[$event][$channel] ?? false); ?>
                    <td>
                        <label class="check">
                            <input type="checkbox"
                                   name="prefs[<?= $e($event) ?>][<?= $e($channel) ?>]"
                                   value="1"<?= $checked ? ' checked' : '' ?>>
                            <span class="visually-hidden"><?= $e($label) ?> via <?= $e($channels[$channel]) ?></span>
                        </label>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p class="hint">
        Email is off by default: a fresh install must not start mailing people because an event
        fired. In-app notifications are on.
    </p>

    <div class="actions">
        <button class="button button--primary" type="submit">Save switches</button>
    </div>
</form>
