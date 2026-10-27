<?php

declare(strict_types=1);

/**
 * Approved comments for an article.
 *
 * Display only. Posting is not offered because this platform has no reader identity: `comments`
 * requires a user id and the only sign-in is the staff admin. Showing a form that cannot succeed
 * would be worse than showing none, and pretending anonymous comments work would be a lie in the
 * interface rather than in a document.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$comments = $view->get('comments', []);
$open = (bool) $view->get('comments_open', false);
$count = is_array($comments) ? count($comments) : 0;

// Nothing to say and nothing to allow: do not print an empty section.
if ($count === 0 && !$open) {
    return;
}
?>
<section class="comments" id="comments">
  <h2 class="comments__title">
    <?= $view->e($count === 1 ? 'One comment' : $count . ' comments') ?>
  </h2>

  <?php if ($count === 0): ?>
    <p class="comments__empty">No comments yet.</p>
  <?php else: ?>
    <ol class="comments__list">
      <?php foreach ($comments as $comment): ?>
        <?php
        $depth = (int) ($comment->depth ?? 0);
        $author = (string) ($comment->authorName ?? '');
        $body = (string) ($comment->body ?? '');
        $at = $comment->createdAt ?? null;
        ?>
        <li class="comment<?= $depth > 0 ? ' comment--reply' : '' ?>"
            <?php if ($depth > 0): ?>style="margin-left: <?= min(4, $depth) * 1.5 ?>rem"<?php endif; ?>>
          <p class="comment__meta">
            <strong class="comment__author"><?= $view->e($author) ?></strong>
            <?php if ($at instanceof \DateTimeImmutable): ?>
              <time class="comment__time" datetime="<?= $view->e($at->format(DATE_ATOM)) ?>">
                <?= $view->e($at->format('j M Y, H:i')) ?>
              </time>
            <?php endif; ?>
          </p>
          <p class="comment__body"><?= nl2br($view->e($body), false) ?></p>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>
