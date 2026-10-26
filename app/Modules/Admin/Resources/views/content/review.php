<?php

declare(strict_types=1);

/**
 * Review screen.
 *
 * Shows the article as a reader would see it, the workflow history, and only the steps this
 * reviewer may actually take. The buttons come from `allowedTransitions()` filtered by
 * `requiredCapability()`, so the screen cannot offer a step the server will refuse.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$content = $view->get('content');
$available = $view->get('available', []);
$history = $view->get('history', []);
$csrf = $view->get('csrf');

$formatDate = static fn (mixed $value): string => $value instanceof \DateTimeImmutable
    ? $value->format('j M Y, H:i')
    : (is_string($value) && $value !== '' ? $value : '');
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title"><?= $e($content->title) ?></h1>
        <p class="page-head__sub">
            <span class="badge badge--<?= $e($content->status) ?>"><?= $e($content->status) ?></span>
            <?php if ($content->excerpt !== null && $content->excerpt !== ''): ?>
                <span class="muted"><?= $e($content->excerpt) ?></span>
            <?php endif; ?>
        </p>
    </div>

    <p class="page-head__back">
        <a href="/admin/content/<?= $e($content->id) ?>">Open the editor</a>
        &middot;
        <a href="/admin/content">&larr; All content</a>
    </p>
</div>

<div class="editor">
    <section class="editor__main">
        <div class="preview">
            <?php
            // Already compiled and escaped by the block compiler when the article was saved.
            // Re-escaping it here would print the markup instead of rendering it.
            echo $content->contentHtml ?? '';
            ?>
        </div>
    </section>

    <aside class="editor__side">
        <?php if ($available !== []): ?>
            <form class="panel" method="post" action="/admin/content/<?= $e($content->id) ?>/transition">
                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                <h2 class="panel__title">Decision</h2>

                <label class="field">
                    <span class="field__label">Note</span>
                    <textarea class="field__input" name="note" rows="3"
                              placeholder="What needs to change, or why this is approved"></textarea>
                </label>

                <div class="actions actions--stack">
                    <?php foreach ($available as $target): ?>
                        <button class="button button--small" type="submit" name="to" value="<?= $e($target) ?>">
                            <?= $e(ucfirst(str_replace('_', ' ', (string) $target))) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </form>
        <?php else: ?>
            <div class="panel">
                <h2 class="panel__title">Decision</h2>
                <p class="empty">You have no available steps for this article at its current stage.</p>
            </div>
        <?php endif; ?>

        <div class="panel">
            <h2 class="panel__title">History</h2>

            <?php if ($history === []): ?>
                <p class="empty">No workflow steps recorded yet.</p>
            <?php else: ?>
                <ol class="history">
                    <?php foreach ($history as $entry): ?>
                        <?php
                        // The workflow returns entities, not rows: its properties are camelCase.
                        $row = is_object($entry) ? get_object_vars($entry) : (array) $entry;

                        $from = (string) ($row['fromStatus'] ?? $row['from_status'] ?? '');
                        $to = (string) ($row['toStatus'] ?? $row['to_status'] ?? '');
                        $note = (string) ($row['note'] ?? '');
                        $at = $row['createdAt'] ?? $row['created_at'] ?? null;
                        ?>
                        <li class="history__item">
                            <span class="history__move"><?= $e($from !== '' ? $from : 'start') ?> &rarr; <?= $e($to) ?></span>
                            <span class="history__when"><?= $e($formatDate($at)) ?></span>
                            <?php if ($note !== ''): ?>
                                <span class="history__note"><?= $e($note) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </aside>
</div>
