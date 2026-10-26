<?php

declare(strict_types=1);

/**
 * The editor.
 *
 * Structure matters here. HTML forbids nested forms, so the page is several sibling forms:
 * the article itself, and one per side panel. That is why the panels each save separately
 * rather than travelling with the main Save button.
 *
 * Adding a block needs no round trip: one blank paragraph row is always rendered after the
 * real ones, and the compiler drops it when it is left empty. Removing a block is clearing
 * its text and saving.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$content = $view->get('content');
$values = $view->get('values', []);
$errors = $view->get('errors', []);
$action = (string) $view->get('action', '/admin/content');
$editable = (bool) $view->get('editable', true);
$available = $view->get('available', []);
$types = $view->get('types', []);
$categories = $view->get('categories', []);
$rows = $values['rows'] ?? [];
$selectedTerms = $values['terms'] ?? [];
$featured = $view->get('featured');
$csrf = $view->get('csrf');

$error = static function (string $field) use ($errors): string {
    $list = $errors[$field] ?? [];
    $first = is_array($list) && $list !== [] ? (string) reset($list) : '';

    return $first;
};

// One trailing empty paragraph: the place a new block is typed.
$rows[] = ['type' => 'paragraph', 'text' => '', 'level' => 2, 'items' => '', 'ordered' => false, 'url' => '', 'alt' => ''];
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title"><?= $content === null ? 'New article' : $e($content->title) ?></h1>
        <?php if ($content !== null): ?>
            <p class="page-head__sub">
                <span class="badge badge--<?= $e($content->status) ?>"><?= $e($content->status) ?></span>
                <?php if ($content->isPubliclyVisible()): ?>
                    <a href="/blog/<?= $e($content->slug) ?>" target="_blank" rel="noopener">View on the site</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>

    <p class="page-head__back"><a href="/admin/content">&larr; All content</a></p>
</div>

<?php if (!$editable): ?>
    <p class="notice notice--info">
        This article is read-only at this stage. It can be edited again when a revision is requested.
    </p>
<?php endif; ?>

<div class="editor">
    <form class="editor__main" method="post" action="<?= $e($action) ?>">
        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">

        <label class="field">
            <span class="field__label">Title</span>
            <input class="field__input field__input--title" type="text" name="title" required
                   value="<?= $e($values['title'] ?? '') ?>"<?= $editable ? '' : ' readonly' ?>>
            <?php if ($error('title') !== ''): ?>
                <span class="field__error"><?= $e($error('title')) ?></span>
            <?php endif; ?>
        </label>

        <label class="field">
            <span class="field__label">Slug</span>
            <input class="field__input" type="text" name="slug"
                   value="<?= $e($values['slug'] ?? '') ?>" placeholder="generated from the title"
                <?= $editable ? '' : ' readonly' ?>>
        </label>

        <fieldset class="blocks"<?= $editable ? '' : ' disabled' ?>>
            <legend class="field__label">Content</legend>

            <?php foreach ($rows as $index => $row): ?>
                <?php $type = (string) ($row['type'] ?? 'paragraph'); ?>
                <div class="block">
                    <div class="block__head">
                        <label class="block__type">
                            <span class="visually-hidden">Block type</span>
                            <select class="field__input" name="blocks[<?= $e($index) ?>][type]">
                                <?php foreach ($types as $option): ?>
                                    <option value="<?= $e($option) ?>"<?= $option === $type ? ' selected' : '' ?>>
                                        <?= $e(ucfirst($option)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <?php if ($type === 'heading'): ?>
                            <label class="block__level">
                                <span class="visually-hidden">Level</span>
                                <select class="field__input" name="blocks[<?= $e($index) ?>][data][level]">
                                    <?php for ($level = 1; $level <= 6; $level++): ?>
                                        <option value="<?= $e($level) ?>"<?= (int) ($row['level'] ?? 2) === $level ? ' selected' : '' ?>>
                                            H<?= $e($level) ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </label>
                        <?php endif; ?>
                    </div>

                    <?php if ($type === 'list'): ?>
                        <textarea class="field__input field__input--text" name="blocks[<?= $e($index) ?>][data][items]"
                                  rows="4" placeholder="One item per line"><?= $e($row['items'] ?? '') ?></textarea>
                        <label class="check">
                            <input type="checkbox" name="blocks[<?= $e($index) ?>][data][ordered]" value="1"
                                <?= !empty($row['ordered']) ? 'checked' : '' ?>>
                            <span>Numbered list</span>
                        </label>
                    <?php elseif ($type === 'image'): ?>
                        <input class="field__input" type="text" name="blocks[<?= $e($index) ?>][data][url]"
                               value="<?= $e($row['url'] ?? '') ?>" placeholder="Image URL">
                        <input class="field__input" type="text" name="blocks[<?= $e($index) ?>][data][alt]"
                               value="<?= $e($row['alt'] ?? '') ?>" placeholder="Alternative text">

                        <?php if (($row['url'] ?? '') !== ''): ?>
                            <img class="block__preview" src="<?= $e($row['url']) ?>" alt="" loading="lazy">
                        <?php endif; ?>

                        <?php if ($content !== null): ?>
                            <a class="button button--small"
                               href="/admin/media?pick=block&amp;content_id=<?= $e($content->id) ?>&amp;index=<?= $e($index) ?>">
                                Choose from the library
                            </a>
                        <?php else: ?>
                            <p class="hint">Save the article first to choose an image from the library.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <textarea class="field__input field__input--text" name="blocks[<?= $e($index) ?>][data][text]"
                                  rows="<?= $type === 'code' ? 6 : 3 ?>"
                                  placeholder="<?= $type === 'heading' ? 'Heading text' : 'Write here' ?>"><?= $e($row['text'] ?? '') ?></textarea>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <p class="hint">
                One blank block is always shown. Type into it to add a block; clear a block to remove it.
            </p>
        </fieldset>

        <label class="field">
            <span class="field__label">Excerpt</span>
            <textarea class="field__input" name="excerpt" rows="2"
                      placeholder="One or two sentences for listings"><?= $e($values['excerpt'] ?? '') ?></textarea>
        </label>

        <?php if ($editable): ?>
            <div class="actions">
                <button class="button button--primary" type="submit">Save</button>
            </div>
        <?php endif; ?>
    </form>

    <aside class="editor__side">
        <?php if ($content !== null): ?>
            <div class="panel">
                <h2 class="panel__title">Featured image</h2>

                <?php if ($featured !== null): ?>
                    <img class="panel__thumb" src="<?= $e($featured->url()) ?>"
                         alt="<?= $e($featured->altText ?? '') ?>" loading="lazy">

                    <?php if ($editable): ?>
                        <form method="post" action="/admin/content/<?= $e($content->id) ?>/featured">
                            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="media_id" value="0">
                            <button class="button button--small button--block" type="submit">Remove image</button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="empty">None set.</p>
                <?php endif; ?>

                <?php if ($editable): ?>
                    <a class="button button--small button--block"
                       href="/admin/media?pick=featured&amp;content_id=<?= $e($content->id) ?>">
                        <?= $featured === null ? 'Choose image' : 'Replace image' ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($content !== null && $editable): ?>
            <form class="panel" method="post" action="/admin/content/<?= $e($content->id) ?>/terms">
                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                <h2 class="panel__title">Categories</h2>

                <?php if ($categories === []): ?>
                    <p class="empty">No categories yet.</p>
                <?php else: ?>
                    <?php foreach ($categories as $term): ?>
                        <label class="check">
                            <input type="checkbox" name="term_ids[]" value="<?= $e($term->id) ?>"
                                <?= in_array((int) $term->id, array_map('intval', (array) $selectedTerms), true) ? 'checked' : '' ?>>
                            <span><?= $e($term->name) ?></span>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>

                <button class="button button--small" type="submit">Save categories</button>
            </form>
        <?php endif; ?>

        <?php if ($content !== null && $editable): ?>
            <form class="panel" method="post" action="/admin/content/<?= $e($content->id) ?>/seo">
                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                <h2 class="panel__title">Search appearance</h2>

                <label class="field">
                    <span class="field__label">Meta title</span>
                    <input class="field__input" type="text" name="meta_title" maxlength="255"
                           value="<?= $e($values['seo']['meta_title'] ?? '') ?>">
                </label>

                <label class="field">
                    <span class="field__label">Meta description</span>
                    <textarea class="field__input" name="meta_description" rows="3" maxlength="320"><?= $e($values['seo']['meta_description'] ?? '') ?></textarea>
                </label>

                <button class="button button--small" type="submit">Save SEO</button>
            </form>
        <?php endif; ?>

        <?php if ($content !== null && $available !== []): ?>
            <form class="panel" method="post" action="/admin/content/<?= $e($content->id) ?>/transition">
                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                <h2 class="panel__title">Workflow</h2>

                <label class="field">
                    <span class="field__label">Note (optional)</span>
                    <textarea class="field__input" name="note" rows="2"
                              placeholder="Explain a revision request"></textarea>
                </label>

                <div class="actions actions--stack">
                    <?php foreach ($available as $target): ?>
                        <button class="button button--small" type="submit" name="to" value="<?= $e($target) ?>">
                            <?= $e(ucfirst(str_replace('_', ' ', (string) $target))) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($view->get('canSchedule')): ?>
            <form class="panel" method="post" action="/admin/content/<?= $e($content->id) ?>/schedule">
                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                <h2 class="panel__title">Schedule</h2>

                <?php if ($content->scheduledAt !== null): ?>
                    <p class="hint">
                        Scheduled for
                        <strong><?= $e($content->scheduledAt->format('j M Y, H:i')) ?></strong> UTC.
                        Choosing another time replaces it.
                    </p>
                <?php else: ?>
                    <p class="hint">Cron publishes it at the chosen time.</p>
                <?php endif; ?>

                <label class="field">
                    <span class="field__label">Publish at</span>
                    <input class="field__input" type="datetime-local" name="publish_at"
                           value="<?= $e($view->get('scheduledLocal')) ?>">
                </label>

                <button class="button button--small button--block" type="submit">
                    <?= $content->scheduledAt === null ? 'Schedule' : 'Reschedule' ?>
                </button>
            </form>
        <?php endif; ?>

        <?php if ($view->get('canSubmit')): ?>
            <form class="panel" method="post" action="/admin/content/<?= $e($content->id) ?>/submit">
                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                <h2 class="panel__title">Ready?</h2>
                <p class="hint">Submitting sends this to a reviewer.</p>
                <button class="button button--primary button--block" type="submit">Submit for review</button>
            </form>
        <?php endif; ?>
    </aside>
</div>
