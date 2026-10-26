<?php

declare(strict_types=1);

/**
 * Content list.
 *
 * One template for both audiences: an editor sees everything, a contributor sees only their
 * own work. The controller decides the scope; this file only renders what it is given.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$e = static fn (mixed $value): string => $view->e($value);

$result = $view->get('result', ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 0]);
$status = (string) $view->get('status', '');
$search = (string) $view->get('search', '');
$mineOnly = (bool) $view->get('mineOnly', false);

$statuses = ['', 'draft', 'submitted', 'review', 'revision_requested', 'approved', 'scheduled', 'published'];
$formatDate = static fn (?object $value): string => $value instanceof \DateTimeImmutable
    ? $value->format('j M Y')
    : '';
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title">Content</h1>
        <p class="page-head__sub">
            <?= $mineOnly ? 'Your articles' : 'All articles' ?>
            &middot; <?= $e($result['total']) ?> total
        </p>
    </div>

    <?php if ($view->get('canCreate')): ?>
        <a class="button button--primary" href="/admin/content/new">New article</a>
    <?php endif; ?>
</div>

<form class="filters" method="get" action="/admin/content">
    <label class="field field--inline">
        <span class="field__label">Search</span>
        <input class="field__input" type="search" name="q" value="<?= $e($search) ?>" placeholder="Title">
    </label>

    <label class="field field--inline">
        <span class="field__label">Status</span>
        <select class="field__input" name="status">
            <?php foreach ($statuses as $option): ?>
                <option value="<?= $e($option) ?>"<?= $option === $status ? ' selected' : '' ?>>
                    <?= $option === '' ? 'Any' : $e($option) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <button class="button" type="submit">Filter</button>
</form>

<?php if ($result['items'] === []): ?>
    <p class="empty">No articles match. <?= $mineOnly ? 'Anything you start appears here.' : '' ?></p>
<?php else: ?>
    <table class="table">
        <thead>
        <tr>
            <th scope="col">Title</th>
            <th scope="col">Status</th>
            <th scope="col">Author</th>
            <th scope="col">Edited</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($result['items'] as $item): ?>
            <tr>
                <td>
                    <a href="/admin/content/<?= $e($item->id) ?>"><?= $e($item->title) ?></a>
                </td>
                <td><span class="badge badge--<?= $e($item->status) ?>"><?= $e($item->status) ?></span></td>
                <td class="muted"><?= $e($item->authorId) ?></td>
                <td class="muted"><?= $e($formatDate($item->updatedAt)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (($result['total_pages'] ?? 0) > 1): ?>
        <nav class="pager" aria-label="Pagination">
            <?php for ($page = 1; $page <= (int) $result['total_pages']; $page++): ?>
                <?php
                $query = array_filter(['q' => $search, 'status' => $status, 'page' => $page]);
                $href = '/admin/content?' . http_build_query($query);
                ?>
                <a class="pager__link<?= $page === (int) $result['page'] ? ' is-active' : '' ?>"
                   href="<?= $e($href) ?>"><?= $e($page) ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
